# shellcheck shell=bash
# MariaDB 11.8 LTS from Debian. Root only via the unix socket, localhost only.

mysql_root() { mariadb --protocol=socket -uroot "$@"; }

module_mariadb() {
  apt_install mariadb-server mariadb-client

  local ram
  ram=$(ram_mb)
  MARIADB_BUFFER_POOL=$(( ram / 4 ))
  (( MARIADB_BUFFER_POOL < 128 )) && MARIADB_BUFFER_POOL=128
  MARIADB_LOG_FILE_SIZE=$(( MARIADB_BUFFER_POOL / 4 ))
  (( MARIADB_LOG_FILE_SIZE < 64 )) && MARIADB_LOG_FILE_SIZE=64

  render "$TPL/mariadb/90-whp.cnf" /etc/mysql/mariadb.conf.d/90-whp.cnf
  svc_enable mariadb
  svc_restart mariadb

  # Same as mariadb-secure-installation, without questions.
  run mysql_root -e "DELETE FROM mysql.global_priv WHERE User='';
    DELETE FROM mysql.global_priv WHERE User='root' AND Host NOT IN ('localhost','127.0.0.1','::1');
    DROP DATABASE IF EXISTS test;
    DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
    FLUSH PRIVILEGES;"
  ok "mariadb $(mariadb --version | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -n1), buffer pool ${MARIADB_BUFFER_POOL} MB"
}
