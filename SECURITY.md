# Security policy

## Project status

This is an alpha source candidate. It processes personal work activity and can write to Kimai only after an explicit opt-in. It is not a payroll-certified, compliance-certified or independently audited application. No signed desktop releases are available.

## Reporting a vulnerability

Once the repository is published, maintainers should **enable GitHub private vulnerability reporting** under Security settings. Use that private reporting channel for authentication, token-handling, file-access, privacy, injection, insecure update or duplicate-write vulnerabilities. **Do not place proof-of-concept exploits or credentials in public issues.**

The public contact and coordinated-disclosure procedure must be completed before the first public release. Until it exists, do not claim that this project has an active vulnerability-response SLA or a monitored security inbox.

## Security invariants

- Tokens never belong in issues, commits, screenshots or test output.
- External integrations must use HTTPS or explicitly limited localhost access.
- Unsafe or ambiguous writes must fail closed without automatic retries.
- Multiple users and profiles must remain isolated.
- New integrations must be opt-in and minimize locally retained data.
