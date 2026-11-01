// Fortify's routes come from a vendor package, so Wayfinder does not generate
// helpers for them. Keep every Fortify URL here instead of scattering strings.
export const fortify = {
    login: '/login',
    logout: '/logout',
    twoFactorChallenge: '/two-factor-challenge',
    confirmPassword: '/user/confirm-password',
    confirmedPasswordStatus: '/user/confirmed-password-status',
    password: '/user/password',
    twoFactor: '/user/two-factor-authentication',
    twoFactorConfirm: '/user/confirmed-two-factor-authentication',
    twoFactorQrCode: '/user/two-factor-qr-code',
    twoFactorSecretKey: '/user/two-factor-secret-key',
    recoveryCodes: '/user/two-factor-recovery-codes',
} as const;
