{{-- Visual rules shared by the Agenda page and the "Agendar" card on the lead (loaded once). --}}
@pushOnce('scripts')
        <style>
            .ag{--ag-bg:#fff;--ag-fg:#111827;--ag-muted:#6b7280;--ag-border:#e5e7eb;--ag-soft:#f3f4f6;--ag-accent:#0e90d9;display:flex;flex-direction:column;gap:12px;color:var(--ag-fg);font-size:14px}
            .dark .ag{--ag-bg:#111827;--ag-fg:#e5e7eb;--ag-muted:#9ca3af;--ag-border:#1f2937;--ag-soft:#1f2937}
            .ag *{box-sizing:border-box}
            .ag button{cursor:pointer;font:inherit;color:inherit}
            .ag-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px 16px;border:1px solid var(--ag-border);border-radius:8px;background:var(--ag-bg)}
            .ag-bar h1{margin:0;font-size:18px;font-weight:600}
            .ag-actions{display:flex;gap:8px;flex-wrap:wrap}
            .ag-btn{padding:7px 14px;border:1px solid var(--ag-border);border-radius:6px;background:var(--ag-bg);font-size:13px;font-weight:500}
            .ag-btn:hover{background:var(--ag-soft)}
            .ag-btn.is-primary{background:var(--ag-accent);border-color:var(--ag-accent);color:#fff}
            .ag-btn.is-primary:hover{filter:brightness(.95)}
            .ag-btn.is-danger{border-color:#dc2626;color:#dc2626}
            .ag-btn:disabled{opacity:.5;cursor:default}
            .ag-filters{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:10px 16px;border:1px solid var(--ag-border);border-radius:8px;background:var(--ag-bg)}
            .ag-label{font-size:12px;color:var(--ag-muted);font-weight:600}
            .ag-chips{display:flex;gap:6px;flex-wrap:wrap}
            .ag-chip{padding:4px 12px;border:1px solid var(--ag-border);border-radius:999px;background:var(--ag-bg);font-size:12px}
            .ag-chip.is-on{background:var(--ag-accent);border-color:var(--ag-accent);color:#fff}
            .ag-check{display:flex;align-items:center;gap:6px;font-size:13px;margin-left:auto}
            .ag-body{display:flex;gap:12px;align-items:flex-start}
            .ag-cal{flex:1;min-width:0;border:1px solid var(--ag-border);border-radius:8px;background:var(--ag-bg);padding:8px}
            .ag-side{width:280px;flex:none;display:flex;flex-direction:column;gap:12px}
            @media (max-width:1100px){.ag-body{flex-direction:column;align-items:stretch}.ag-side{width:100%}}
            .ag-card{border:1px solid var(--ag-border);border-radius:8px;background:var(--ag-bg);padding:12px;display:flex;flex-direction:column;gap:8px}
            .ag-card h2{margin:0;font-size:13px;font-weight:600}
            .ag-muted{color:var(--ag-muted);font-size:12px}
            .ag-item{display:flex;flex-direction:column;gap:2px;width:100%;padding:8px 10px;border:1px solid var(--ag-border);border-left-width:4px;border-radius:6px;background:var(--ag-bg);text-align:left}
            .ag-item:hover{background:var(--ag-soft)}
            .ag-item.is-done{opacity:.6}
            .ag-legend{display:flex;flex-direction:column;gap:6px}
            .ag-legend span{display:flex;align-items:center;gap:8px;font-size:12px}
            .ag-dot{width:10px;height:10px;border-radius:50%;flex:none}
            .ag-event{height:100%;padding:3px 6px;overflow:hidden;border-left:4px solid;border-radius:4px;color:#111827;font-size:12px;line-height:1.25;text-align:left}
            .ag-event strong{display:block;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
            .ag-event span,.ag-event small{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
            .ag-event small{opacity:.8}
            .ag-event.is-done{opacity:.55;text-decoration:line-through}
            .ag-event.is-overdue{box-shadow:inset 0 0 0 1px #dc2626}
            .ag-back{position:fixed;inset:0;z-index:10005;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.45)}
            .ag-modal{width:100%;max-width:480px;max-height:90vh;overflow:auto;display:flex;flex-direction:column;gap:12px;padding:20px;border-radius:10px;background:var(--ag-bg);color:var(--ag-fg);box-shadow:0 20px 40px rgba(0,0,0,.25)}
            .ag-modal h2{margin:0;font-size:16px;font-weight:600}
            .ag-field{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:var(--ag-muted)}
            .ag-field input,.ag-field select,.ag-field textarea{width:100%;padding:8px 10px;border:1px solid var(--ag-border);border-radius:6px;background:var(--ag-bg);color:var(--ag-fg);font:inherit;font-weight:400}
            .ag-row{display:flex;gap:10px}
            .ag-row>*{flex:1;min-width:0}
            .ag-results{border:1px solid var(--ag-border);border-radius:6px;overflow:hidden}
            .ag-results button{display:flex;flex-direction:column;width:100%;padding:8px 10px;border:0;border-bottom:1px solid var(--ag-border);background:var(--ag-bg);text-align:left}
            .ag-results button:last-child{border-bottom:0}
            .ag-results button:hover{background:var(--ag-soft)}
            .ag-picked{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:8px 10px;border:1px solid var(--ag-accent);border-radius:6px}
            .ag-alert{padding:10px 12px;border-radius:6px;background:#fef3c7;color:#92400e;font-size:13px}
            .ag-detail{display:grid;grid-template-columns:110px 1fr;gap:6px 10px;font-size:13px}
            .ag-detail dt{color:var(--ag-muted)}
            .ag-detail dd{margin:0}
            .ag-footer{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
            .ag-kind{display:flex;gap:6px}
            .ag-kind button{flex:1}
            .ag-hint{font-size:12px;color:var(--ag-muted);font-weight:400}
            .ag-event.is-followup{border-left-style:dashed;border-top:1px dashed;border-right:1px dashed;border-bottom:1px dashed}
            .ag-tag{display:inline-block;margin-right:4px;padding:0 6px;border-radius:999px;background:rgba(0,0,0,.08);font-size:10px;font-weight:600}
            .ag .vuecal{background:transparent}
            .ag .vuecal__event{background:transparent!important;border:0!important}
            .ag .vuecal__cell--today{background:rgba(14,144,217,.06)}
            .ag .vuecal__heading{font-size:12px;line-height:1.15}
            .ag .vuecal__heading .weekday-label{display:flex;flex-direction:column;align-items:center;gap:0}
            .ag .vuecal__heading .weekday-label span:nth-child(2){font-size:11px;opacity:.7}
        </style>
@endPushOnce
