# Managed by the Webhosting Panel (mail.spam_policy_sync). Do not edit.
require ["fileinto", "mailbox", "envelope", "regex"];

if header :regex "X-Spam-Level" "^\\*{10,}" {
    fileinto :create "Quarantine";
    stop;
}
if header :is "X-Spam" "Yes" {
    fileinto :create "Junk";
    stop;
}
