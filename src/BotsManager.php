<?php

namespace Telegram\Bot;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Telegram\Bot\Exceptions\TelegramSDKException;

/**
 * Class BotsManager.
 *
 * @mixin \Telegram\Bot\Api
 */
class BotsManager
{
    /** @var array The config instance. */
    protected $config;

    /** @var Container The container instance. */
    protected $container;

    /** @var array<string, Api> The active bot instances. */
    protected $bots = [];

    /**
     * TelegramManager constructor.
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Set the IoC Container.
     *
     * @param  Container  $container Container instance
     */
    public function setContainer(Container $container): self
    {
        $this->container = $container;

        return $this;
    }

    /**
     * Get the configuration for a bot.
     *
     * @param  string|null  $name
     *
     * @throws InvalidArgumentException
     */
    public function getBotConfig($name = null): array
    {
        $name = $name ?? $this->getDefaultBotName();

        $bots = collect($this->getConfig('bots'));

        if (! $config = $bots->get($name, null)) {
            throw new InvalidArgumentException("Bot [$name] not configured.");
        }

        $config['bot'] = $name;

        return $config;
    }

    /**
     * Get a bot instance.
     *
     * @param  string|null  $name
     *
     * @throws TelegramSDKException
     */
    public function bot($name = null): Api
    {
        $name = $name ?? $this->getDefaultBotName();

        if (! isset($this->bots[$name])) {
            $this->bots[$name] = $this->makeBot($name);
        }

        return $this->bots[$name];
    }

    /**
     * Reconnect to the given bot.
     *
     * @param  string|null  $name
     *
     * @throws TelegramSDKException
     */
    public function reconnect($name = null): Api
    {
        $name = $name ?? $this->getDefaultBotName();
        $this->disconnect($name);

        return $this->bot($name);
    }

    /**
     * Disconnect from the given bot.
     *
     * @param  string|null  $name
     */
    public function disconnect($name = null): self
    {
        $name = $name ?? $this->getDefaultBotName();
        unset($this->bots[$name]);

        return $this;
    }

    /**
     * Get the specified configuration value for Telegram.
     *
     * @param  string  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function getConfig($key, $default = null)
    {
        return data_get($this->config, $key, $default);
    }

    /**
     * Get the default bot name.
     *
     * @return string|null
     */
    public function getDefaultBotName()
    {
        return $this->getConfig('default');
    }

    /**
     * Set the default bot name.
     *
     * @param  string  $name
     */
    public function setDefaultBot($name): self
    {
        Arr::set($this->config, 'default', $name);

        return $this;
    }

    /**
     * Return all of the created bots.
     *
     * @return array<string, Api>
     */
    public function getBots(): array
    {
        return $this->bots;
    }

    /**
     * De-duplicate an array.
     */
    protected function deduplicateArray(array $array): array
    {
        return array_values(array_unique($array));
    }

    /**
     * Make the bot instance.
     *
     * @param  string  $name
     *
     * @throws TelegramSDKException
     */
    protected function makeBot($name): Api
    {
        $config = $this->getBotConfig($name);

        $token = data_get($config, 'token');

        $telegram = new Api(
            $token,
            $this->getConfig('async_requests', false),
            $this->getConfig('http_client_handler', null),
            $this->getConfig('base_bot_url', null)
        );

        // Check if DI needs to be enabled for Commands
        if ($this->getConfig('resolve_command_dependencies', false) && isset($this->container)) {
            $telegram->setContainer($this->container);
        }

        $commands = data_get($config, 'commands', []);
        $commands = $this->parseBotCommands($commands);

        // Register Commands
        $telegram->addCommands($commands);

        return $telegram;
    }

    /**
     * @deprecated Will be removed in SDK v4
     *
     * @internal
     * Builds the list of commands for the given commands array.
     *
     * @param  list<string|class-string<\Telegram\Bot\Commands\CommandInterface>>  $commands A list of command names or FQCNs of CommandInterface instances.
     * @return array An array of commands which includes global and bot specific commands.
     */
    public function parseBotCommands(array $commands): array
    {
        $globalCommands = $this->getConfig('commands', []);
        $parsedCommands = $this->parseCommands($commands);

        return $this->deduplicateArray(array_merge($globalCommands, $parsedCommands));
    }

    /**
     * Parse an array of commands and build a list.
     *
     * @param  list<string|class-string<\Telegram\Bot\Commands\CommandInterface>>  $commands
     */
    protected function parseCommands(array $commands): array
    {
        $commandGroups = $this->getConfig('command_groups');
        $sharedCommands = $this->getConfig('shared_commands');

        //TODO: This is ripe for refactor / collections.
        $results = [];
        foreach ($commands as $command) {
            // If the command is a group, we'll parse through the group of commands
            // and resolve the full class name.
            if (isset($commandGroups[$command])) {
                $results = array_merge(
                    $results,
                    $this->parseCommands($commandGroups[$command])
                );

                continue;
            }

            // If this command is actually a shared command, we'll extract the full
            // class name out of the command list now.
            if (isset($sharedCommands[$command])) {
                $command = $sharedCommands[$command];
            }

            if (! in_array($command, $results)) {
                $results[] = $command;
            }
        }

        return $results;
    }

    /**
     * Magically pass methods to the default bot.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     *
     * @throws TelegramSDKException
     */
    public function __call($method, $parameters)
    {
        return call_user_func_array([$this->bot(), $method], $parameters);
    }
}
