# Set up and manage authenticator MFA

MFA uses widely supported **30-second 6-digit TOTP** codes. It does not depend on a third-party login provider, external QR-code service, or JavaScript build pipeline.

1. Sign in normally and open **My Account → Sign-in protection**.
2. Select **Enable two-factor authentication**, confirm your existing TaskFlow password.
3. In an authenticator app such as Aegis, 1Password, Google Authenticator or Microsoft Authenticator, choose **enter setup key manually**. Copy the key displayed by TaskFlow. Set account name to your TaskFlow email and use time-based codes, SHA-1, six digits, 30 seconds.
4. Type the current six-digit code to activate MFA. Save all **10 one-use recovery codes offline** before dismissing them. Codes are never shown again.
5. On your next password sign-in, enter your current authenticator code, or one unused recovery code. Remembered devices may remain signed in once authorized, but are revoked on MFA or password changes.

**Recovering access:** enter one of your unused recovery codes on the verification screen. If all recovery options are lost, there is currently **no supported self-service recovery path**; a trusted server/database administrator must follow your organization's documented account recovery procedure after verifying identity. Never email stored MFA secrets or make emergency SQL changes without a backup and audit trail.

**Changing devices:** use the authenticator app's protected transfer feature, or use a valid TOTP/recovery code plus password to disable and re-enroll. Avoid disabling MFA simply because a phone has changed when a secure transfer is available.

**Organization policy:** enrolling MFA is currently user-driven. Administrators should enroll, and high-sensitivity organizations should also enforce mandatory MFA via a trusted identity-aware proxy until TaskFlow has an org-wide MFA enforcement setting.
