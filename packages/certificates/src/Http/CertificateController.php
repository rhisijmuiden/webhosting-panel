<?php

declare(strict_types=1);

namespace Rhis\Certificates\Http;

use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Rhis\Certificates\CertificateException;
use Rhis\Certificates\CertificateManager;
use Rhis\Certificates\CertificateParser;
use Rhis\Certificates\CsrGenerator;
use Rhis\Certificates\Models\SiteCertificate;
use Symfony\Component\HttpFoundation\Response;

/** SSL certificates of websites; the admin and client controllers only differ in which websites they see. */
abstract class CertificateController extends Controller
{
    protected string $area = 'admin';

    public function __construct(protected readonly CertificateManager $manager) {}

    abstract protected function websites(Request $request): Builder;

    protected function canManage(): bool
    {
        return true;
    }

    protected function authorizeWebsite(Request $request, Website $website): void
    {
        abort_unless($this->websites($request)->whereKey($website->id)->exists(), 404);
    }

    protected function authorizeManage(Request $request, Website $website): void
    {
        $this->authorizeWebsite($request, $website);
        abort_unless($this->canManage(), 403);
    }

    public function index(Request $request): View
    {
        $websites = $this->websites($request)->orderBy('domain')->get(['id', 'domain', 'customer_id', 'parent_id', 'ssl_status', 'status', 'aliases']);
        $records = SiteCertificate::whereIn('website_id', $websites->pluck('id'))->get()->keyBy('website_id');
        $rows = $websites->map(fn (Website $w) => ['website' => $w, 'cert' => $records[$w->id] ?? null]);

        $filter = (string) $request->query('filter', '');
        if ($filter === 'attention') {
            $rows = $rows->filter(fn ($r) => ! $r['cert'] || $r['cert']->health() !== 'ok');
        } elseif (in_array($filter, SiteCertificate::SOURCES, true)) {
            $rows = $rows->filter(fn ($r) => ($r['cert']?->source ?? 'none') === $filter);
        }
        $all = $records->values();

        return view('certificates::index', [
            'area' => $this->area,
            'rows' => $rows->values(),
            'filter' => $filter,
            'stats' => [
                'total' => $websites->count(),
                'secure' => $all->filter(fn ($c) => $c->source !== 'none' && ! $c->isExpired())->count(),
                'soon' => $all->filter(fn ($c) => $c->health() !== 'ok' && $c->source !== 'none' && ! $c->isExpired())->count(),
                'expired' => $all->filter(fn ($c) => $c->isExpired())->count(),
                'custom' => $all->where('source', 'custom')->count(),
                'wildcard' => $all->where('source', 'wildcard')->count(),
            ],
            'checkedAt' => $all->max('checked_at'),
        ]);
    }

    public function show(Request $request, Website $website): View
    {
        $this->authorizeWebsite($request, $website);
        $cert = $this->manager->for($website);
        if (! $cert->isBusy() && (! $cert->checked_at || $cert->checked_at->lt(now()->subMinutes(5)))) {
            $cert = $this->manager->refresh($website);
        }
        $parentWildcard = $this->manager->parentWildcard($website);

        return view('certificates::show', [
            'area' => $this->area,
            'website' => $website,
            'cert' => $cert,
            'hosts' => $this->manager->hostNames($website),
            'uncovered' => $cert->source !== 'none' ? CertificateParser::uncovered((array) $cert->names, $this->manager->hostNames($website)) : [],
            'canManage' => $this->canManage(),
            'wildcardBlocker' => $parentWildcard ? null : $this->manager->wildcardBlocker($website),
            'parentWildcard' => $parentWildcard,
            'keyTypes' => CsrGenerator::KEY_TYPES,
        ]);
    }

    public function upload(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeManage($request, $website);
        $data = $request->validate([
            'format' => ['required', 'in:pem,pfx'],
            'certificate' => ['nullable', 'string', 'max:65000'],
            'certificate_file' => ['nullable', 'file', 'max:64'],
            'private_key' => ['nullable', 'string', 'max:65000'],
            'private_key_file' => ['nullable', 'file', 'max:64'],
            'chain' => ['nullable', 'string', 'max:65000'],
            'chain_file' => ['nullable', 'file', 'max:64'],
            'passphrase' => ['nullable', 'string', 'max:200'],
            'pfx_file' => ['nullable', 'required_if:format,pfx', 'file', 'max:64'],
            'pfx_password' => ['nullable', 'string', 'max:200'],
        ]);
        $parser = app(CertificateParser::class);
        try {
            if ($data['format'] === 'pfx') {
                $parsed = $parser->fromPfx($this->contents($request->file('pfx_file')), $data['pfx_password'] ?? null);
            } else {
                $certificate = $this->text($data['certificate'] ?? null, $request->file('certificate_file'));
                $key = $this->text($data['private_key'] ?? null, $request->file('private_key_file'));
                $record = $this->manager->for($website);
                if ($key === '' && $record->csr_key) {
                    $key = $record->csr_key; // the key of the CSR made here
                }
                if ($certificate === '') {
                    throw new CertificateException(__('Paste the certificate or choose the certificate file.'));
                }
                $parsed = $parser->fromPem($certificate, $key, $this->text($data['chain'] ?? null, $request->file('chain_file')), $data['passphrase'] ?? null);
            }
            $warnings = $this->manager->installCustom($website, $parsed);
        } catch (CertificateException $e) {
            return back()->withErrors(['certificate' => $e->getMessage()])->withInput($request->only('format', 'chain'));
        }

        return back()->with('status', __('Your own certificate is active for :domain (valid until :date).', [
            'domain' => $website->domain, 'date' => date('d-m-Y', $parsed->notAfter),
        ]))->with('cert_warnings', $warnings);
    }

    public function csr(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeManage($request, $website);
        $data = $request->validate([
            'key_type' => ['required', 'in:'.implode(',', array_keys(CsrGenerator::KEY_TYPES))],
            'organization' => ['nullable', 'string', 'max:64', 'regex:/^[^<>"\/\\\\]*$/'],
            'city' => ['nullable', 'string', 'max:64', 'regex:/^[^<>"\/\\\\]*$/'],
            'state' => ['nullable', 'string', 'max:64', 'regex:/^[^<>"\/\\\\]*$/'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'www' => ['nullable', 'boolean'],
        ]);
        try {
            $this->manager->makeCsr($website, $data['key_type'], $data, (bool) ($data['www'] ?? true));
        } catch (CertificateException $e) {
            return back()->withErrors(['csr' => $e->getMessage()]);
        }

        return back()->with('status', __('The CSR is ready. Give it to your certificate supplier; the private key stays safely in the panel.'));
    }

    public function csrDownload(Request $request, Website $website): Response
    {
        $this->authorizeWebsite($request, $website);
        $csr = $this->manager->for($website)->csr;
        abort_unless($csr, 404);

        return response($csr, 200, [
            'Content-Type' => 'application/pkcs10',
            'Content-Disposition' => 'attachment; filename="'.$website->domain.'.csr"',
        ]);
    }

    public function csrDestroy(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeManage($request, $website);
        $this->manager->dropCsr($website);

        return back()->with('status', __('The CSR and its key were removed.'));
    }

    public function wildcard(Request $request, Website $website): RedirectResponse
    {
        return $this->request($request, $website, 'wildcard');
    }

    public function letsencrypt(Request $request, Website $website): RedirectResponse
    {
        return $this->request($request, $website, 'letsencrypt');
    }

    private function request(Request $request, Website $website, string $kind): RedirectResponse
    {
        $this->authorizeManage($request, $website);
        try {
            $this->manager->request($website, $kind);
        } catch (CertificateException $e) {
            return back()->withErrors(['certificate' => $e->getMessage()]);
        }

        return back()->with('status', $kind === 'wildcard'
            ? __('The wildcard certificate is being requested. This takes one to three minutes: a DNS record is placed at Cloudflare and checked by Let\'s Encrypt.')
            : __('A Let\'s Encrypt certificate is being requested. The current certificate stays in use until it is issued.'));
    }

    public function refresh(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeWebsite($request, $website);
        $this->manager->refresh($website);

        return back()->with('status', __('Certificate details read again from the server.'));
    }

    private function text(?string $pasted, ?UploadedFile $file): string
    {
        $pasted = trim((string) $pasted);

        return $pasted !== '' ? $pasted : ($file ? trim($this->contents($file)) : '');
    }

    private function contents(?UploadedFile $file): string
    {
        return $file && $file->isValid() ? (string) file_get_contents($file->getRealPath()) : '';
    }
}
