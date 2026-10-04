<?php

namespace App\Console\Commands;

use App\Services\SecurityAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ArchiveSecurityAudit extends Command
{
    protected $signature = 'security:audit-archive {--after=0 : Export sequences after this checkpoint} {--limit=10000 : Maximum records in this archive}';
    protected $description = 'Export append-only audit evidence to the independently configured archive disk; never delete local evidence';

    public function handle()
    {
        $disk = config('admin_security.archive_disk');
        if (!$disk) { $this->error('请先配置 ADMIN_AUDIT_ARCHIVE_DISK，并在存储服务启用对象锁/不可变保留策略。'); return 1; }
        $after = filter_var($this->option('after'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($after === false || $after < 0 || $limit === false || $limit < 1 || $limit > 10000) { $this->error('after 必须非负，limit 必须介于 1 和 10000。'); return 1; }
        $checkpoint = SecurityAuditService::verify();
        if (!$checkpoint['valid']) { $this->error('审计完整性校验失败，停止归档。'); return 1; }
        $intent = SecurityAuditService::append('audit.archive', 'pending', ['after' => $after, 'limit' => $limit, 'disk' => $disk]);
        $stream = fopen('php://temp/maxmemory:5242880', 'w+');
        try {
            $rows = DB::table('v2_admin_audit')->where('id', '>', $after)->where('id', '<=', $checkpoint['sequence'])->orderBy('id')->limit($limit)->get();
            fwrite($stream, json_encode(['checkpoint' => $checkpoint, 'after' => $after, 'last' => $rows->last()->id ?? $after], JSON_UNESCAPED_SLASHES) . "\n");
            foreach ($rows as $row) fwrite($stream, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            rewind($stream);
            $object = 'security-audit/' . gmdate('Y/m/d') . '/' . $after . '-' . ($rows->last()->id ?? $after) . '-' . bin2hex(random_bytes(16)) . '.jsonl';
            if (!Storage::disk($disk)->put($object, $stream, ['visibility' => 'private'])) throw new \RuntimeException('归档存储写入失败');
            SecurityAuditService::append('audit.archive', 'success', ['intent_id' => $intent['id'], 'object' => $object, 'disk' => $disk, 'count' => $rows->count(), 'checkpoint' => $checkpoint]);
            $this->info('归档已写入 ' . $object . '；下次 after=' . ($rows->last()->id ?? $after) . '。原始记录保留。');
            return 0;
        } catch (\Throwable $error) {
            SecurityAuditService::append('audit.archive', 'failure', ['intent_id' => $intent['id'], 'exception_type' => get_class($error)]);
            $this->error('归档失败，原始记录仍然保留。请检查存储连接和权限。');
            return 1;
        } finally { fclose($stream); }
    }
}
