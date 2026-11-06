# Debian 13 with systemd as PID 1, close to a fresh VM. Used by CI only.
FROM debian:trixie
ENV container=docker DEBIAN_FRONTEND=noninteractive
# CI only: keep downloaded .debs (the workflow caches them) and skip fsync in
# dpkg, which makes the many package installs a lot faster in a throwaway box.
RUN apt-get update -q \
 && apt-get install -y -q --no-install-recommends systemd systemd-sysv dbus ca-certificates curl \
      git openssh-server procps iproute2 kmod \
 && rm -f /etc/machine-id && systemd-machine-id-setup \
 && systemctl mask systemd-firstboot.service getty@tty1.service \
 && rm -rf /var/lib/apt/lists/* \
 && rm -f /etc/apt/apt.conf.d/docker-clean \
 && echo 'Binary::apt::APT::Keep-Downloaded-Packages "true";' > /etc/apt/apt.conf.d/10keep-debs \
 && echo force-unsafe-io > /etc/dpkg/dpkg.cfg.d/unsafe-io
STOPSIGNAL SIGRTMIN+3
CMD ["/lib/systemd/systemd"]
