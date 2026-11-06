"""whp-agent: the root daemon of the Webhosting Panel.

The panel talks to it over a Unix socket. Only registered actions can run,
and actions never hand their parameters to a shell.
"""

__version__ = "0.1.0"
