# AI Module Notes

## Current state

The current public repository does not include a production LLM inference module. There is no built-in OpenAI, Anthropic, or local-model runtime in the application itself.

## Why this document exists

The project is being published for open-source collaboration and future AI-assisted development. This file documents the practical extension points where an AI module can be added safely.

## Good integration points

- admin assistant for summarizing bookings and user activity
- moderation or anomaly detection for suspicious booking patterns
- natural-language task generation for loyalty campaigns
- documentation generation and repository maintenance
- analytics interpretation for retention and reward usage

## Safe boundaries

If an AI module is added later, it should:

- never read raw `.env` or secrets into prompts
- avoid exposing phone numbers, emails, or Telegram identifiers
- operate on filtered API payloads
- log only sanitized metadata
- preserve manual admin approval for user-impacting actions

## Suggested architecture for future AI work

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
