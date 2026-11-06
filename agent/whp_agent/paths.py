import os

ROOT_DIR = os.environ.get("WHP_ROOT", "/opt/webhosting-panel")
CONFIG_DIR = os.environ.get("WHP_CONFIG_DIR", "/etc/webhosting-panel")
STATE_DIR = os.environ.get("WHP_STATE_DIR", "/var/lib/webhosting-panel")
LOG_DIR = os.environ.get("WHP_LOG_DIR", "/var/log/webhosting-panel")
SOCKET_PATH = os.environ.get("WHP_AGENT_SOCKET", "/run/whp-agent/agent.sock")
JOBS_DIR = os.path.join(STATE_DIR, "jobs")
PANEL_ENV = os.path.join(CONFIG_DIR, "panel.env")
INSTALLER_ENV = os.path.join(CONFIG_DIR, "installer.env")
NFT_DIR = os.environ.get("WHP_NFT_DIR", "/etc/nftables.d")
