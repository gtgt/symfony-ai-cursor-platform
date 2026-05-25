# Symfony AI Cursor Platform

Platform bridge for [Cursor](https://cursor.com) in the Symfony AI stack.

- [Symfony AI Bundle](https://symfony.com/doc/current/ai/bundles/ai-bundle.html) — YAML configuration, agents, console commands
- [Symfony AI Agent component](https://symfony.com/doc/current/ai/components/agent.html) — `AgentInterface`, messages, tools

Pick **one** backend:

- **CLI** — local `agent` command (typical for development)
- **Cloud** — Cursor Cloud Agents API (typical for CI / remote runs)

## Installation

```bash
composer require gtgt/symfony-ai-cursor-platform symfony/ai-bundle symfony/ai-agent
```

## Quick start (CLI)

`config/packages/ai_platform_cursor.yaml`:

```yaml
ai_platform_cursor:
    cli: ~
```

`config/packages/ai.yaml`:

```yaml
ai:
    agent:
        default:
            platform: 'ai.platform.cursor_cli'
            model: 'default'
            tools: false
```

Use `tools: false` so Cursor handles its own tooling; Symfony toolbox tools are not passed through.

`.env` (only if you use an API key with the CLI instead of `agent login`):

```dotenv
# CURSOR_API_KEY=
```

### Try it

```bash
php bin/console ai:agent:call default
```

### Use in PHP

```php
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

final readonly class Assistant
{
    public function __construct(
        private AgentInterface $agent, // ai.agent.default
    ) {
    }

    public function ask(string $question): string
    {
        return $this->agent->call(new MessageBag(Message::ofUser($question)));
    }
}
```

Common CLI settings (optional), still under `ai_platform_cursor.cli`:

```yaml
ai_platform_cursor:
    cli:
        workspace: '%kernel.project_dir%'
        trust: true
        force: false
```

Per-call overrides are passed as agent options, e.g. `['cursor_force' => true]`.

## Alternative: Cloud

Replace the CLI config with:

`config/packages/ai_platform_cursor.yaml`:

```yaml
ai_platform_cursor:
    cloud:
        api_key: '%env(CURSOR_API_KEY)%'
        repositories:
            - url: 'https://github.com/your-org/your-repo.git'
```

`config/packages/ai.yaml`:

```yaml
ai:
    agent:
        default:
            platform: 'ai.platform.cursor'
            model: 'default'
            tools: false
```

```dotenv
CURSOR_API_KEY=your_api_key_here
```

```bash
php bin/console ai:agent:call default
```

## Models

Use `default` for Cursor’s default model, or any model id supported by your CLI / cloud account.

## Symfony documentation

- [AI Bundle](https://symfony.com/doc/current/ai/bundles/ai-bundle.html) — `ai.platform`, `ai.agent`, model options, `ai:agent:call`
- [Agent component](https://symfony.com/doc/current/ai/components/agent.html) — programmatic usage, processors, toolbox
- [Symfony AI overview](https://symfony.com/doc/current/ai/index.html)

## License

LGPL-3.0-or-later — see [LICENSE](LICENSE).
