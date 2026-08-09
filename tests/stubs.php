<?php

/**
 * Stand-ins for everything AutoUpdateService talks to.
 *
 * The service's constructor is typed against its collaborators, so these are
 * declared in the real namespace and loaded first: PHP then never reaches for
 * the real files, and the phase logic can be driven without a panel, a database
 * or a game server. Each one records what it was asked to do; the tests assert
 * on the recordings.
 *
 * StateStore is the exception and is loaded for real, because its defaults and
 * clamping are exactly what the phases are supposed to respect.
 */

namespace {
    // Laravel helpers the service reaches for, in the shapes it actually uses.
    if (!function_exists('now')) {
        function now()
        {
            return new class {
                public int $timestamp;

                public function __construct()
                {
                    $this->timestamp = time();
                }

                public function format(string $f): string
                {
                    return date($f);
                }
            };
        }
    }

    if (!function_exists('config')) {
        function config($key, $default = null)
        {
            return $default;
        }
    }

    if (!function_exists('app')) {
        function app($class = null)
        {
            // Only the backup service is resolved this way, and a backup that
            // cannot start must not take a restart down with it. Throwing here
            // is the interesting case, not an accident.
            return new class {
                public function handle(...$args)
                {
                    throw new \RuntimeException('no backup service in tests');
                }
            };
        }
    }
}

namespace Illuminate\Support\Facades {
    class Log
    {
        /** @var array<int,string> */
        public static array $lines = [];

        public static function __callStatic($name, $args)
        {
            self::$lines[] = $name . ': ' . ($args[0] ?? '');
        }
    }
}

namespace App\Models {
    /** Only what the service touches: a console sink and egg variables. */
    class Server
    {
        public int $id = 1;

        /** @var array<int,string> */
        public static array $sent = [];

        public array $variables = [];

        public function __construct(string $autoUpdate = '1')
        {
            $this->variables = [
                (object) ['env_variable' => 'AUTO_UPDATE', 'server_value' => $autoUpdate, 'default_value' => '0', 'id' => 9],
                (object) ['env_variable' => 'SRCDS_APPID', 'server_value' => '380870', 'default_value' => '', 'id' => 10],
            ];
        }

        public static function reset(): void
        {
            self::$sent = [];
        }

        public function send($command)
        {
            self::$sent[] = is_array($command) ? implode(' ', $command) : $command;

            return null;
        }
    }

    class ServerVariable
    {
        public static function updateOrCreate(array $keys, array $values): void {}
    }

    class Backup
    {
        /** Stands for a backup that has not finished yet. */
        public const RUNNING = 77;

        public $completed_at = null;

        public static function find($id)
        {
            $b = new self();
            $b->completed_at = $id === self::RUNNING ? null : 'done';

            return $b;
        }
    }
}

namespace App\Services\Backups {
    class InitiateBackupService {}
}

namespace WildBrianNL\PZModManager\Services {
    class IniService
    {
        /** @var array<int,string> */
        public static array $mods = ['ModA'];

        public function read($server): array
        {
            return ['ok' => true, 'mods' => self::$mods, 'workshopItems' => [], 'maps' => [],
                    'raw' => '', 'path' => 'x', 'error' => null];
        }
    }

    class ModScanner
    {
        public static array $installed = [
            ['mod_id' => 'ModA', 'workshop_id' => '111', 'installed_at' => 1000],
        ];

        public function index($server, int $build): array
        {
            return ['ok' => true, 'mods' => self::$installed, 'fingerprint' => 'x'];
        }
    }

    class SteamClient
    {
        public static array $details = ['111' => ['updated' => 1000, 'title' => 'Mod A']];

        /** Counted, because how often Steam is contacted is itself a requirement. */
        public static int $calls = 0;

        public function details(array $ids, ?int $maxAge = null): array
        {
            self::$calls++;

            return self::$details;
        }
    }

    class LogInspector
    {
        public static ?int $players = 0;

        public static bool $started = true;

        public function inspect($server): array
        {
            return ['ok' => true, 'started' => self::$started, 'loaded' => [], 'notFound' => [],
                    'errors' => [], 'names' => [], 'fatal' => null, 'build' => 42, 'file' => 'x'];
        }

        public function latestLogLength($server): ?int
        {
            return 0;
        }

        public function latestPlayersCountSince($server, int $offset): ?int
        {
            return self::$players;
        }
    }

    class GameBuild
    {
        public static array $result = ['outdated' => false, 'installed' => 1, 'latest' => 1];

        public function compare($server): array
        {
            return self::$result;
        }

        public function appId($server): string
        {
            return '380870';
        }
    }

    class PowerService
    {
        /** @var array<int,string> */
        public static array $sent = [];

        public static function reset(): void
        {
            self::$sent = [];
        }

        public function setServer($server): self
        {
            return $this;
        }

        public function send(string $action): void
        {
            self::$sent[] = $action;
        }
    }
}

namespace {
    // The real StateStore, with the file repository swapped out. Its defaults
    // and clamping are exactly what the phases are meant to respect, so faking
    // them wholesale would make the tests agree with themselves rather than with
    // the code that ships.
    require __DIR__ . '/../src/Services/StateStore.php';

    class MemoryStore extends WildBrianNL\PZModManager\Services\StateStore
    {
        public array $state;

        /** Deliberately does not call the parent constructor: there is no daemon here. */
        public function __construct(array $auto, array $run)
        {
            $this->state = ['pending' => [], 'locks' => [], 'auto' => $auto, 'run' => $run];
        }

        public function read($server): array
        {
            return $this->state;
        }

        public function write($server, array $state): void
        {
            $this->state = $state;
        }
    }
}
