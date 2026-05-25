<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Cursor\Cli;

use Symfony\AI\Platform\Bridge\Cursor\MessagePayload;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Maps each Platform invocation to the Cursor CLI agent ({@code agent -p --output-format json ...}).
 *
 * Works with Legacy Privacy mode because inference runs locally via the CLI rather than the
 * Cloud Agents REST API.
 */
final class ModelClient implements ModelClientInterface
{
    private const DEFAULT_BINARY = 'agent';
    private const DEFAULT_TIMEOUT = 600;

    public function __construct(
        private readonly string $binary = self::DEFAULT_BINARY,
        #[\SensitiveParameter] private readonly ?string $apiKey = null,
        private readonly ?string $workspace = null,
        private readonly bool $trust = true,
        private readonly bool $force = false,
        private readonly ?string $sandbox = null,
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
        /** @var list<string> */
        private readonly array $defaultArgs = [],
    ) {
    }

    public function supports(Model $model): bool
    {
        return $model instanceof Agent;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        if (!\is_array($payload)) {
            throw new InvalidArgumentException(\sprintf('Payload must be an array, string given to "%s".', self::class));
        }

        $promptText = MessagePayload::flattenMessages(MessagePayload::requireMessages($payload));
        $command = $this->buildCommand($model, $promptText, $options);

        $env = null;
        if (null !== $this->apiKey && '' !== $this->apiKey) {
            $env = ['CURSOR_API_KEY' => $this->apiKey];
        }

        $process = new Process($command, $this->resolveWorkspace($options), $env, null, $this->resolveTimeout($options));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $stdout = rtrim($process->getOutput());
        if ('' === $stdout) {
            throw new RuntimeException('Cursor CLI returned empty output.');
        }

        $outputFormat = (string) ($options['cursor_output_format'] ?? 'json');
        if ('stream-json' === $outputFormat) {
            $lines = array_values(array_filter(
                explode("\n", $stdout),
                static fn (string $line): bool => '' !== trim($line),
            ));

            return new InMemoryRawResult(
                ['_cli_stdout_lines' => $lines],
                [],
                (object) ['status' => 200],
            );
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($stdout, true, 512, \JSON_THROW_ON_ERROR);

        return new InMemoryRawResult(
            $data,
            [],
            (object) ['status' => 200],
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    private function buildCommand(Model $model, string $prompt, array $options): array
    {
        $binary = (string) ($options['cursor_cli_binary'] ?? $this->binary);
        if ('' === trim($binary)) {
            throw new InvalidArgumentException('Option "cursor_cli_binary" cannot be empty.');
        }

        $outputFormat = (string) ($options['cursor_output_format'] ?? 'json');
        if (!\in_array($outputFormat, ['json', 'text', 'stream-json'], true)) {
            throw new InvalidArgumentException(\sprintf('Option "cursor_output_format" must be one of json, text, stream-json; "%s" given.', $outputFormat));
        }

        $command = [$binary, '-p', '--output-format', $outputFormat];

        if ($options['cursor_stream_partial'] ?? false) {
            $command[] = '--stream-partial-output';
        }

        if ($options['cursor_trust'] ?? $this->trust) {
            $command[] = '--trust';
        }

        if ($options['cursor_force'] ?? $this->force) {
            $command[] = '--force';
        }

        if (isset($options['cursor_yolo']) && $options['cursor_yolo']) {
            $command[] = '--yolo';
        }

        if (isset($options['cursor_sandbox'])) {
            $sandbox = (string) $options['cursor_sandbox'];
            if (!\in_array($sandbox, ['enabled', 'disabled'], true)) {
                throw new InvalidArgumentException('Option "cursor_sandbox" must be "enabled" or "disabled".');
            }
            $command[] = '--sandbox';
            $command[] = $sandbox;
        } elseif (null !== $this->sandbox) {
            $command[] = '--sandbox';
            $command[] = $this->sandbox;
        }

        if (isset($options['cursor_mode'])) {
            $mode = (string) $options['cursor_mode'];
            if (!\in_array($mode, ['plan', 'ask'], true)) {
                throw new InvalidArgumentException('Option "cursor_mode" must be "plan" or "ask".');
            }
            $command[] = '--mode';
            $command[] = $mode;
        }

        if ($options['cursor_plan'] ?? false) {
            $command[] = '--plan';
        }

        if ($options['cursor_approve_mcps'] ?? false) {
            $command[] = '--approve-mcps';
        }

        $apiKey = $options['cursor_api_key'] ?? $this->apiKey;
        if (\is_string($apiKey) && '' !== $apiKey) {
            $command[] = '--api-key';
            $command[] = $apiKey;
        }

        $modelName = $model->getName();
        if ('default' !== $modelName) {
            $command[] = '--model';
            $command[] = $modelName;
        }

        $workspace = $options['cursor_workspace'] ?? $this->workspace;
        if (\is_string($workspace) && '' !== $workspace) {
            $command[] = '--workspace';
            $command[] = $workspace;
        }

        if (isset($options['cursor_resume'])) {
            $resume = $options['cursor_resume'];
            if (true === $resume) {
                $command[] = '--resume';
            } elseif (\is_string($resume) && '' !== $resume) {
                $command[] = '--resume';
                $command[] = $resume;
            } else {
                throw new InvalidArgumentException('Option "cursor_resume" must be true or a session id string.');
            }
        } elseif (isset($options['cursor_session_id']) && \is_string($options['cursor_session_id']) && '' !== $options['cursor_session_id']) {
            $command[] = '--resume';
            $command[] = $options['cursor_session_id'];
        }

        if ($options['cursor_continue'] ?? false) {
            $command[] = '--continue';
        }

        $extraArgs = $options['cursor_extra_args'] ?? $this->defaultArgs;
        if (!\is_array($extraArgs)) {
            throw new InvalidArgumentException('Option "cursor_extra_args" must be a list of CLI argument strings.');
        }
        foreach ($extraArgs as $arg) {
            if (!\is_string($arg)) {
                throw new InvalidArgumentException('Option "cursor_extra_args" must contain only strings.');
            }
            $command[] = $arg;
        }

        $command[] = $prompt;

        return $command;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveWorkspace(array $options): ?string
    {
        $workspace = $options['cursor_workspace'] ?? $this->workspace;

        return \is_string($workspace) && '' !== $workspace ? $workspace : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveTimeout(array $options): float
    {
        $timeout = $options['cursor_timeout'] ?? $this->timeout;

        if (!\is_int($timeout) && !\is_float($timeout)) {
            throw new InvalidArgumentException('Option "cursor_timeout" must be an integer or float (seconds).');
        }

        if ($timeout <= 0) {
            throw new InvalidArgumentException('Option "cursor_timeout" must be greater than zero.');
        }

        return (float) $timeout;
    }
}
