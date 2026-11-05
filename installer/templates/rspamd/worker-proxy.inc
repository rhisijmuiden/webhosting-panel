# Managed by the Webhosting Panel installer: milter for Postfix.
milter = yes;
bind_socket = "127.0.0.1:11332";
timeout = 120s;
upstream "local" {
  default = yes;
  self_scan = yes;
}
