# Security Policy

## Reporting a vulnerability

Please do not open a public issue for sensitive vulnerabilities.

Report security problems privately to the project maintainer before disclosure. Include:

- affected file or feature
- reproduction steps
- impact assessment
- suggested mitigation, if available

## Scope

Security-sensitive areas in this repository include:

- Telegram webhook handling
- WebApp request validation
- admin access flows
- SQLite data storage
- SMTP/email verification
- environment variable handling

## Repository hygiene

- never commit `.env` files
- never commit production databases or logs
- rotate secrets immediately if they were exposed
- verify server rules block access to `.env`, `.db`, and log files
