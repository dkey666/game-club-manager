# AI Module Notes

## Why this document exists

The project includes an AI-oriented analytics direction focused on helping gaming club administrators understand behavior, usage patterns, and operational bottlenecks. This file documents the role of that module and the practical extension points around it.

## Analytics goals

The AI module is intended to analyze:

- user behavior and engagement patterns inside the Telegram WebApp
- booking statistics across halls and individual PCs
- peak attendance days and recurring traffic trends
- reward usage, referral activity, and task completion patterns
- operational weak points and improvement opportunities for administrators

## Example outputs

The analytics layer can support recommendations such as:

- which hall is booked most frequently
- which individual PCs are the most demanded
- which days of the week attract the highest number of visitors
- which engagement mechanics are driving repeat usage
- which operational changes could improve retention or workstation utilization

## Safe boundaries

The analytics module should:

- never read raw `.env` or secrets into prompts
- avoid exposing phone numbers, emails, or Telegram identifiers
- operate on filtered API payloads
- log only sanitized metadata
- preserve manual admin approval for user-impacting actions

## Suggested architecture

1. Add a separate service boundary such as `/ai/*` endpoints or a worker.
2. Use explicit prompt templates stored in version control.
3. Pass minimized structured input, not raw database dumps.
4. Keep all outbound model credentials in environment variables.
5. Add opt-in feature flags before enabling any automation in production.

## Codex/Open Source Fund relevance

This repository is now structured so AI tools can contribute safely:

- reproducible installation
- explicit dependency manifests
- documented configuration
- documented architecture
- clear separation between tracked source and ignored runtime data
