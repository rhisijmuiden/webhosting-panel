# Managed by the Webhosting Panel installer: use the local Unbound resolver.
dns {
  nameserver = ["127.0.0.1:53:10"];
}
local_addrs = "127.0.0.0/8, ::1";
