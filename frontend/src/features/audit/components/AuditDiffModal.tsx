import {
  Dialog,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/shared/utils/cn';
import type { AuditLog } from '../types/audit.types';

export interface AuditDiffModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  log: AuditLog | null;
}

function formatValue(value: unknown): string {
  if (value === null || value === undefined) {
    return 'null';
  }
  if (typeof value === 'object') {
    return JSON.stringify(value, null, 2);
  }
  if (typeof value === 'boolean') {
    return value ? 'true' : 'false';
  }
  return String(value);
}

function getEventVariant(event: string | null): 'default' | 'success' | 'destructive' | 'warning' {
  switch (event?.toLowerCase()) {
    case 'created':
      return 'success';
    case 'updated':
      return 'warning';
    case 'deleted':
      return 'destructive';
    default:
      return 'default';
  }
}

export function AuditDiffModal({ open, onOpenChange, log }: AuditDiffModalProps) {
  if (!log) return null;

  const oldChanges = log.changes?.old ?? null;
  const newChanges = log.changes?.attributes ?? null;

  // Extract all distinct keys between old and new
  const allKeys = Array.from(
    new Set([
      ...(oldChanges ? Object.keys(oldChanges) : []),
      ...(newChanges ? Object.keys(newChanges) : []),
    ])
  );

  const hasDiff = allKeys.length > 0;
  const otherProperties = Object.entries(log.properties ?? {}).filter(
    ([k]) => k !== 'old' && k !== 'attributes'
  );

  return (
    <Dialog open={open} onOpenChange={onOpenChange} contentClassName="max-w-2xl max-h-[85vh] overflow-y-auto">
      <DialogHeader>
        <div className="flex items-center gap-2 mb-1">
          <Badge variant={getEventVariant(log.event)}>
            {log.event?.toUpperCase() ?? 'LOG'}
          </Badge>
          <span className="text-xs text-muted-foreground">ID #{log.id}</span>
        </div>
        <DialogTitle className="text-xl">Audit Log Details</DialogTitle>
        <DialogDescription>{log.description || 'System activity log entry'}</DialogDescription>
      </DialogHeader>

      {/* Metadata Grid */}
      <div className="grid grid-cols-2 gap-3 p-3 my-3 rounded-md bg-muted/40 text-xs border border-border">
        <div>
          <span className="font-semibold text-foreground">Subject: </span>
          <span className="text-muted-foreground font-mono">
            {log.subject_type ? log.subject_type.split('\\').pop() : '—'} ({log.subject_id ?? '—'})
          </span>
        </div>
        <div>
          <span className="font-semibold text-foreground">Actor (Causer): </span>
          <span className="text-muted-foreground">
            {log.causer?.name ?? (log.causer_id ? `User ${log.causer_id}` : 'System')}
          </span>
        </div>
        <div>
          <span className="font-semibold text-foreground">IP Address: </span>
          <span className="text-muted-foreground font-mono">{log.ip_address ?? '—'}</span>
        </div>
        <div>
          <span className="font-semibold text-foreground">Date: </span>
          <span className="text-muted-foreground">
            {log.created_at ? new Date(log.created_at).toLocaleString() : '—'}
          </span>
        </div>
        {log.user_agent && (
          <div className="col-span-2 truncate">
            <span className="font-semibold text-foreground">User Agent: </span>
            <span className="text-muted-foreground" title={log.user_agent}>
              {log.user_agent}
            </span>
          </div>
        )}
      </div>

      {/* Changes / Diffs */}
      <div className="space-y-3 mt-4">
        <h4 className="text-sm font-semibold text-foreground">Attribute Changes (Diff)</h4>
        {hasDiff ? (
          <div className="border border-border rounded-md overflow-hidden">
            <div className="grid grid-cols-3 bg-muted/60 p-2 text-xs font-semibold text-muted-foreground border-b border-border">
              <div>Field</div>
              <div>Before (Old)</div>
              <div>After (New)</div>
            </div>
            <div className="divide-y divide-border">
              {allKeys.map((key) => {
                const oldVal = oldChanges ? oldChanges[key] : undefined;
                const newVal = newChanges ? newChanges[key] : undefined;
                const isModified = oldVal !== undefined && newVal !== undefined && oldVal !== newVal;
                const isAdded = oldVal === undefined && newVal !== undefined;
                const isRemoved = oldVal !== undefined && newVal === undefined;

                return (
                  <div
                    key={key}
                    className={cn(
                      'grid grid-cols-3 p-2.5 text-xs font-mono items-start gap-2',
                      isAdded && 'bg-emerald-500/10',
                      isRemoved && 'bg-rose-500/10',
                      isModified && 'bg-amber-500/10'
                    )}
                  >
                    <div className="font-semibold text-foreground break-all">{key}</div>
                    <div className="text-rose-700 dark:text-rose-400 break-all whitespace-pre-wrap">
                      {oldVal !== undefined ? (
                        <span className={cn(isModified && 'line-through opacity-80')}>
                          {formatValue(oldVal)}
                        </span>
                      ) : (
                        <span className="text-muted-foreground italic">empty</span>
                      )}
                    </div>
                    <div className="text-emerald-700 dark:text-emerald-400 break-all whitespace-pre-wrap font-medium">
                      {newVal !== undefined ? (
                        formatValue(newVal)
                      ) : (
                        <span className="text-muted-foreground italic">empty</span>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        ) : (
          <p className="text-xs text-muted-foreground italic p-3 border border-dashed rounded-md text-center">
            No before/after attribute diffs recorded for this activity.
          </p>
        )}

        {otherProperties.length > 0 && (
          <div className="mt-4 space-y-2">
            <h4 className="text-xs font-semibold text-foreground">Custom Properties</h4>
            <pre className="p-3 text-xs bg-muted/50 rounded-md border border-border overflow-x-auto font-mono">
              {JSON.stringify(Object.fromEntries(otherProperties), null, 2)}
            </pre>
          </div>
        )}
      </div>

      <DialogFooter>
        <Button variant="outline" onClick={() => onOpenChange(false)}>
          Close
        </Button>
      </DialogFooter>
    </Dialog>
  );
}
