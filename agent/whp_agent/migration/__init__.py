"""Server migration: copy websites, databases, mail and certificates from
another hosting server into this one.

Layout, so later sources (DirectAdmin, cPanel, a single WordPress site) can
reuse the same pieces:

  ssh.py       SSH connection to the source (own key, own known_hosts, multiplexed)
  transfer.py  Source independent copy steps: rsync files and Maildirs,
               stream a database dump into MariaDB, install a certificate
  plesk.py     The Plesk source: inventory (from the psa database), paths,
               mailbox credentials, database user hashes and certificates

A source class answers "what is there and where", transfer.py does the
copying. The actions live in whp_agent/actions/migrate.py.
"""
