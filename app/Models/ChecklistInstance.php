<?php

namespace App\Models;

class ChecklistInstance extends RecordModel
{
    public const STATUSES = ['due' => 'Due', 'in_progress' => 'In progress', 'awaiting_verification' => 'Waiting for verifier', 'completed' => 'Completed', 'overdue' => 'Overdue', 'void' => 'Void'];

    protected $casts = ['due_at' => 'datetime', 'overdue_at' => 'datetime', 'completed_at' => 'datetime'];

    public function template() { return $this->belongsTo(ChecklistTemplate::class, 'template_id'); }
    public function version() { return $this->belongsTo(ChecklistTemplateVersion::class, 'version_id'); }
    public function items() { return $this->hasMany(ChecklistInstanceItem::class, 'instance_id')->orderBy('id'); }
    public function batch() { return $this->belongsTo(Batch::class); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_user_id'); }
    public function performer() { return $this->belongsTo(Person::class, 'performed_by_person_id'); }

    public function isOpen(): bool { return in_array($this->status, ['due', 'in_progress', 'overdue'], true); }

    public function isOverdueNow(): bool
    {
        return $this->isOpen() && $this->overdue_at !== null && $this->overdue_at->isPast();
    }

    public function displayStatus(): string
    {
        return $this->isOverdueNow() ? 'overdue' : $this->status;
    }

    /** "SC-01 · Bench scale" / "Room 101" for scoped checklists. */
    public function getScopeLabelAttribute(): string
    {
        if (! $this->scope_key) {
            return '';
        }
        [$type, $id] = explode(':', $this->scope_key) + [null, null];

        return match ($type) {
            'room' => 'Room '.(Room::find($id)?->room_no ?? $id),
            'equipment' => ($e = Equipment::find($id)) ? $e->equip_id.' · '.$e->name : 'Equipment #'.$id,
            default => '',
        };
    }

    public function scopedEquipment(): ?Equipment
    {
        return str_starts_with((string) $this->scope_key, 'equipment:') ? Equipment::find((int) substr($this->scope_key, 10)) : null;
    }

    public function label(): string { return $this->public_id.' · '.($this->template?->name ?? ''); }
}
