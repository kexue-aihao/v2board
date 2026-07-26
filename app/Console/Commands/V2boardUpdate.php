<?php

namespace App\Console\Commands;

use App\Services\SchemaUpgradeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class V2boardUpdate extends Command
{
    protected $signature = 'v2board:update {--legacy : Execute the historical database/update.sql file}';
    protected $description = 'v2board 更新';

    public function handle(): int
    {
        try {
            \Artisan::call('config:cache');
            DB::connection()->getPdo();
            if (!$this->option('legacy')) {
                $this->info('Running idempotent schema migrations...');
                foreach (app(SchemaUpgradeService::class)->run() as $migration) {
                    $status = $migration['status'] === 'applied' ? 'applied' : 'already applied';
                    $this->info("{$migration['version']}: {$status}");
                }
                $this->info('Database schema is up to date.');
                return self::SUCCESS;
            }

            $this->warn('Legacy mode enabled: executing database/update.sql. Back up the database first.');
            $file = \File::get(base_path() . '/database/update.sql');
            if (!$file) {
                $this->error('数据库文件不存在');
                return self::FAILURE;
            }

            $statements = preg_split('/;/', $file, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($statements)) {
                $this->error('数据库文件格式有误');
                return self::FAILURE;
            }

            $failed = 0;
            $this->info('正在导入数据库，请稍等...');
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if ($statement === '') {
                    continue;
                }
                try {
                    DB::statement($statement);
                } catch (\Throwable $e) {
                    $failed++;
                    $preview = preg_replace('/\s+/', ' ', substr($statement, 0, 180));
                    $this->error("SQL upgrade failed: {$preview}");
                    $this->error($e->getMessage());
                }
            }

            if ($failed > 0) {
                $this->error("Database upgrade failed: {$failed} statement(s).");
                return self::FAILURE;
            }

            \Artisan::call('horizon:terminate');
            $this->info('更新完成');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
