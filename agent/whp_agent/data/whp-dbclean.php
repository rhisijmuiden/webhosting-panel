<?php
/**
 * WordPress database cleanup for the Webhosting Panel.
 *
 * Run by whp-agent as the site user:  wp eval-file - <preview|clean> '<options json>'
 * (this file comes in on stdin, WordPress is loaded with --skip-plugins --skip-themes).
 * It prints one line "WHPJSON:{...}" with the outcome.
 *
 * Options: {"items": ["revisions", ...], "keep_revisions": 0}
 * Only tables of this install (its $table_prefix) are touched.
 */

if (! defined('ABSPATH')) {
    exit(1);
}

global $wpdb;

$whp_mode = isset($args[0]) ? (string) $args[0] : 'preview';
$whp_opt = json_decode(isset($args[1]) ? (string) $args[1] : '{}', true);
if (! is_array($whp_opt)) {
    $whp_opt = array();
}
$whp_keep = max(0, min(100, (int) ($whp_opt['keep_revisions'] ?? 0)));
$whp_items = array_values(array_filter((array) ($whp_opt['items'] ?? array()), 'is_string'));
$whp_now = time();

/** Run fn over $ids in chunks, so huge sites never build one giant query. */
function whp_dbclean_chunks(array $ids, callable $fn, $size = 500)
{
    foreach (array_chunk($ids, $size) as $chunk) {
        $fn(implode(',', array_map('intval', $chunk)));
    }
}

/** Revisions to remove: all of them, or all but the newest $keep of every post. */
function whp_dbclean_revisions($keep)
{
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT ID, post_parent, LENGTH(post_content) + LENGTH(post_title) + LENGTH(post_excerpt) AS b
         FROM {$wpdb->posts} WHERE post_type = 'revision' ORDER BY post_parent, post_date DESC, ID DESC",
        ARRAY_A
    );
    $ids = array();
    $bytes = 0;
    $per = array();
    $seen = array();
    foreach ($rows as $r) {
        $p = (int) $r['post_parent'];
        $seen[$p] = ($seen[$p] ?? 0) + 1;
        if ($seen[$p] <= $keep) {
            continue;
        }
        $ids[] = (int) $r['ID'];
        $bytes += (int) $r['b'];
        $per[$p] = ($per[$p] ?? 0) + 1;
    }
    arsort($per);
    $examples = array();
    foreach (array_slice($per, 0, 5, true) as $parent => $n) {
        $title = $parent ? get_the_title($parent) : '';
        $examples[] = array('label' => $title !== '' ? $title : '#' . $parent, 'count' => $n);
    }

    return array($ids, $bytes, $examples);
}

/** Posts in a status (auto-draft, trash): ids, size and the first titles. */
function whp_dbclean_posts($status)
{
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_title, post_type, LENGTH(post_content) + LENGTH(post_title) AS b
         FROM {$wpdb->posts} WHERE post_status = %s AND post_type <> 'revision' ORDER BY ID DESC",
        $status
    ), ARRAY_A);
    $examples = array();
    foreach (array_slice($rows, 0, 5) as $r) {
        $examples[] = array('label' => ($r['post_title'] !== '' ? $r['post_title'] : '#' . $r['ID']), 'type' => $r['post_type']);
    }

    return array(array_map('intval', wp_list_pluck($rows, 'ID')), array_sum(array_map('intval', wp_list_pluck($rows, 'b'))), $examples);
}

function whp_dbclean_comments($approved)
{
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT comment_ID, comment_author, LENGTH(comment_content) + LENGTH(comment_author) + LENGTH(comment_author_url) AS b
         FROM {$wpdb->comments} WHERE comment_approved = %s ORDER BY comment_ID DESC",
        $approved
    ), ARRAY_A);
    $examples = array();
    foreach (array_slice($rows, 0, 5) as $r) {
        $examples[] = array('label' => $r['comment_author'] !== '' ? $r['comment_author'] : '#' . $r['comment_ID']);
    }

    return array(array_map('intval', wp_list_pluck($rows, 'comment_ID')), array_sum(array_map('intval', wp_list_pluck($rows, 'b'))), $examples);
}

/** Option names of expired transients (value and timeout rows), or of all transients. */
function whp_dbclean_transients($all, $now)
{
    global $wpdb;
    $t = $wpdb->esc_like('_transient_');
    $st = $wpdb->esc_like('_site_transient_');
    if ($all) {
        $rows = $wpdb->get_results(
            "SELECT option_name, LENGTH(option_value) AS b FROM {$wpdb->options}
             WHERE option_name LIKE '{$t}%' OR option_name LIKE '{$st}%'",
            ARRAY_A
        );
        $names = wp_list_pluck($rows, 'option_name');
        $count = count(array_filter($names, function ($n) {
            return strpos($n, '_transient_timeout_') === false;
        }));

        return array($names, array_sum(array_map('intval', wp_list_pluck($rows, 'b'))), $count);
    }
    $timeouts = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options}
         WHERE (option_name LIKE %s OR option_name LIKE %s) AND option_value < %d",
        $wpdb->esc_like('_transient_timeout_') . '%',
        $wpdb->esc_like('_site_transient_timeout_') . '%',
        $now
    ));
    $names = array();
    foreach ($timeouts as $n) {
        $names[] = $n;
        $names[] = str_replace('_transient_timeout_', '_transient_', $n);
    }
    $bytes = 0;
    foreach (array_chunk($names, 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '%s'));
        $bytes += (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE option_name IN ($in)", $chunk));
    }

    return array($names, $bytes, count($timeouts));
}

function whp_dbclean_orphans()
{
    global $wpdb;
    $post = $wpdb->get_col("SELECT m.meta_id FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.ID IS NULL");
    $comment = $wpdb->get_col("SELECT m.meta_id FROM {$wpdb->commentmeta} m LEFT JOIN {$wpdb->comments} c ON c.comment_ID = m.comment_id WHERE c.comment_ID IS NULL");

    return array(array_map('intval', $post), array_map('intval', $comment));
}

/** Tables of this install with their size and free (reclaimable) space. */
function whp_dbclean_tables()
{
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT TABLE_NAME AS name, ENGINE AS engine, COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) AS size, COALESCE(DATA_FREE, 0) AS free
         FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s AND TABLE_TYPE = 'BASE TABLE'",
        DB_NAME,
        $wpdb->esc_like($wpdb->base_prefix) . '%'
    ), ARRAY_A);
    $out = array();
    foreach ($rows as $r) {
        $out[] = array('name' => $r['name'], 'engine' => $r['engine'], 'size' => (int) $r['size'], 'free' => (int) $r['free']);
    }

    return $out;
}

function whp_dbclean_size(array $tables)
{
    return array_sum(wp_list_pluck($tables, 'size'));
}

/** Everything that would be removed, per item. */
function whp_dbclean_preview($keep, $now)
{
    global $wpdb;
    list($rev, $revBytes, $revEx) = whp_dbclean_revisions($keep);
    list($auto, $autoBytes) = whp_dbclean_posts('auto-draft');
    list($trash, $trashBytes, $trashEx) = whp_dbclean_posts('trash');
    list($spam, $spamBytes, $spamEx) = whp_dbclean_comments('spam');
    list($ctrash, $ctrashBytes, $ctrashEx) = whp_dbclean_comments('trash');
    list(, $expBytes, $expCount) = whp_dbclean_transients(false, $now);
    list(, $allBytes, $allCount) = whp_dbclean_transients(true, $now);
    list($opost, $ocomment) = whp_dbclean_orphans();
    $tables = whp_dbclean_tables();
    $overhead = array_values(array_filter($tables, function ($t) {
        return $t['free'] > 0;
    }));
    usort($overhead, function ($a, $b) {
        return $b['free'] <=> $a['free'];
    });

    return array(
        'prefix' => $wpdb->prefix,
        'multisite' => is_multisite(),
        'db_size' => whp_dbclean_size($tables),
        'tables' => count($tables),
        'keep_revisions' => $keep,
        'items' => array(
            'revisions' => array('count' => count($rev), 'bytes' => $revBytes, 'examples' => $revEx),
            'auto_drafts' => array('count' => count($auto), 'bytes' => $autoBytes, 'examples' => array()),
            'trashed_posts' => array('count' => count($trash), 'bytes' => $trashBytes, 'examples' => $trashEx),
            'spam_comments' => array('count' => count($spam), 'bytes' => $spamBytes, 'examples' => $spamEx),
            'trashed_comments' => array('count' => count($ctrash), 'bytes' => $ctrashBytes, 'examples' => $ctrashEx),
            'expired_transients' => array('count' => $expCount, 'bytes' => $expBytes, 'examples' => array()),
            'all_transients' => array('count' => $allCount, 'bytes' => $allBytes, 'examples' => array()),
            'orphaned_meta' => array('count' => count($opost) + count($ocomment), 'bytes' => 0, 'examples' => array(),
                'post_meta' => count($opost), 'comment_meta' => count($ocomment)),
            'optimize' => array('count' => count($overhead), 'bytes' => array_sum(wp_list_pluck($overhead, 'free')),
                'examples' => array_map(function ($t) {
                    return array('label' => $t['name'], 'bytes' => $t['free']);
                }, array_slice($overhead, 0, 5))),
        ),
    );
}

function whp_dbclean_delete_posts(array $ids)
{
    global $wpdb;
    $n = 0;
    whp_dbclean_chunks($ids, function ($in) use ($wpdb, &$n) {
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($in)");
        $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($in)");
        $n += (int) $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ($in)");
    });

    return $n;
}

function whp_dbclean_delete_comments(array $ids)
{
    global $wpdb;
    $n = 0;
    whp_dbclean_chunks($ids, function ($in) use ($wpdb, &$n) {
        $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ($in)");
        $n += (int) $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_ID IN ($in)");
    });

    return $n;
}

function whp_dbclean_clean(array $items, $keep, $now)
{
    global $wpdb;
    $before = whp_dbclean_tables();
    $removed = array();
    $touched = array();

    if (in_array('revisions', $items, true)) {
        list($ids) = whp_dbclean_revisions($keep);
        $removed['revisions'] = whp_dbclean_delete_posts($ids);
        $touched[] = $wpdb->posts;
        $touched[] = $wpdb->postmeta;
    }
    if (in_array('auto_drafts', $items, true)) {
        list($ids) = whp_dbclean_posts('auto-draft');
        $removed['auto_drafts'] = whp_dbclean_delete_posts($ids);
        $touched[] = $wpdb->posts;
    }
    if (in_array('trashed_posts', $items, true)) {
        // Through WordPress, so revisions, comments and media files of the post go too.
        list($ids) = whp_dbclean_posts('trash');
        $n = 0;
        foreach ($ids as $id) {
            if (wp_delete_post($id, true)) {
                $n++;
            }
        }
        $removed['trashed_posts'] = $n;
        $touched[] = $wpdb->posts;
        $touched[] = $wpdb->postmeta;
        $touched[] = $wpdb->comments;
    }
    foreach (array('spam_comments' => 'spam', 'trashed_comments' => 'trash') as $item => $status) {
        if (in_array($item, $items, true)) {
            list($ids) = whp_dbclean_comments($status);
            $removed[$item] = whp_dbclean_delete_comments($ids);
            $touched[] = $wpdb->comments;
            $touched[] = $wpdb->commentmeta;
        }
    }
    $all = in_array('all_transients', $items, true);
    if ($all || in_array('expired_transients', $items, true)) {
        list($names, , $count) = whp_dbclean_transients($all, $now);
        foreach (array_chunk($names, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%s'));
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name IN ($in)", $chunk));
        }
        $removed[$all ? 'all_transients' : 'expired_transients'] = $count;
        $touched[] = $wpdb->options;
    }
    if (in_array('orphaned_meta', $items, true)) {
        list($post, $comment) = whp_dbclean_orphans();
        $n = 0;
        whp_dbclean_chunks($post, function ($in) use ($wpdb, &$n) {
            $n += (int) $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ($in)");
        });
        whp_dbclean_chunks($comment, function ($in) use ($wpdb, &$n) {
            $n += (int) $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE meta_id IN ($in)");
        });
        $removed['orphaned_meta'] = $n;
        $touched[] = $wpdb->postmeta;
        $touched[] = $wpdb->commentmeta;
    }

    $optimized = array();
    if (in_array('optimize', $items, true)) {
        foreach ($before as $t) {
            if ($t['free'] > 0 || in_array($t['name'], $touched, true)) {
                // The name comes from information_schema and matches this install's prefix.
                $wpdb->query('OPTIMIZE TABLE `' . str_replace('`', '', $t['name']) . '`');
                $optimized[] = $t['name'];
            }
        }
        $removed['optimize'] = count($optimized);
    }
    wp_cache_flush();
    $after = whp_dbclean_tables();

    return array(
        'removed' => $removed,
        'optimized' => $optimized,
        'db_size_before' => whp_dbclean_size($before),
        'db_size_after' => whp_dbclean_size($after),
        'keep_revisions' => $keep,
    );
}

$whp_out = $whp_mode === 'clean'
    ? whp_dbclean_clean($whp_items, $whp_keep, $whp_now)
    : whp_dbclean_preview($whp_keep, $whp_now);

echo "\nWHPJSON:" . wp_json_encode($whp_out) . "\n";
