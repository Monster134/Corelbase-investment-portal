/**
 * CORELBASE PRIVATE LIMITED — ADMIN COMMAND CENTER CONTROLLER (js/admin.js)
 * -----------------------------------------------------------------------------
 * Powers:
 * - 11 KPI cards & 5 dynamic bar charts
 * - Instant global search & status pill filtering
 * - Slide-over 6-section Investor Profile Drawer
 * - Confirmation Dialogs (Section 59) before sensitive actions
 * - Share Allotment recording & Share Certificate generation (Section 61)
 * - System, Share Capital, Bank/UPI, Asset & SMTP settings management
 */

(function () {
  'use strict';

  const csrfToken = document.getElementById('adminCsrfToken')?.value || '';
  let currentFilterStatus = 'ALL';
  let activeProfile = null;
  let pendingConfirmCallback = null;

  function formatINR(val) {
    const num = Number(val) || 0;
    return '₹' + num.toLocaleString('en-IN', { maximumFractionDigits: 2 });
  }

  function esc(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function showToast(msg, type = 'info') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const el = document.createElement('div');
    el.className = 'toast';
    if (type === 'success') el.style.borderLeftColor = '#10b981';
    if (type === 'error') el.style.borderLeftColor = '#ef4444';
    el.textContent = msg;
    container.appendChild(el);
    setTimeout(() => el.remove(), 3800);
  }

  async function apiGet(params) {
    const qs = new URLSearchParams(params).toString();
    const res = await fetch(`../api/admin_api.php?${qs}`, { credentials: 'same-origin' });
    return res.json();
  }

  async function apiPost(action, formDataOrObj) {
    let body;
    if (formDataOrObj instanceof FormData) {
      body = formDataOrObj;
      body.set('action', action);
      body.set('csrf_token', csrfToken);
    } else {
      body = new FormData();
      body.set('action', action);
      body.set('csrf_token', csrfToken);
      Object.entries(formDataOrObj || {}).forEach(([k, v]) => body.set(k, String(v ?? '')));
    }
    const res = await fetch('../api/admin_api.php', {
      method: 'POST',
      body,
      credentials: 'same-origin',
    });
    return res.json();
  }

  // 1. Dashboard Overview & Charts
  async function loadDashboard() {
    try {
      const data = await apiGet({ action: 'dashboard_overview' });
      if (!data.success) return;
      const k = data.kpis || {};
      const setTxt = (id, v) => {
        const el = document.getElementById(id);
        if (el) el.textContent = v;
      };
      setTxt('kpiTotalApps', k.total_applications ?? 0);
      setTxt('kpiPendingVerif', k.pending_verification ?? 0);
      setTxt('kpiVerifiedPay', k.verified_payments ?? 0);
      setTxt('kpiApprovedApps', k.approved_applications ?? 0);
      setTxt('kpiSharesAllotted', Number(k.shares_allotted ?? 0).toLocaleString('en-IN'));
      setTxt('kpiCertsGen', k.certificates_generated ?? 0);
      setTxt('kpiTotalApplied', formatINR(k.total_investment_applied ?? 0));
      setTxt('kpiTotalVerified', formatINR(k.total_verified_investment ?? 0));
      setTxt('kpiRejectedApps', k.rejected_applications ?? 0);
      setTxt('kpiDuplicateApps', k.duplicate_applications ?? 0);
      setTxt('kpiCancelledApps', k.cancelled_applications ?? 0);

      const c = data.charts || {};
      renderBarChart(
        'chartAppsOverTime',
        (c.time_series || []).map((r) => ({ label: r.day.slice(5), value: Number(r.app_count) }))
      );
      renderBarChart(
        'chartAmountOverTime',
        (c.time_series || []).map((r) => ({ label: r.day.slice(5), value: Number(r.total_amount) })),
        true
      );
      renderBarChart(
        'chartStatusDist',
        (c.status_distribution || []).map((r) => ({ label: r.status.split(' ')[0], value: Number(r.count) }))
      );
      renderBarChart(
        'chartMonthlyInv',
        (c.monthly_investment || []).map((r) => ({ label: r.month, value: Number(r.amount) })),
        true
      );
    } catch (e) {
      console.error(e);
    }
  }

  function renderBarChart(containerId, items, isCurrency = false) {
    const el = document.getElementById(containerId);
    if (!el) return;
    if (!items || items.length === 0) {
      el.innerHTML = '<div style="margin:auto;color:var(--text-muted);font-size:0.82rem;">No data points recorded yet</div>';
      return;
    }
    const maxVal = Math.max(1, ...items.map((i) => i.value));
    el.innerHTML = items
      .map((item) => {
        const pct = Math.max(6, Math.round((item.value / maxVal) * 125));
        const valLabel = isCurrency ? '₹' + Math.round(item.value / 1000) + 'k' : item.value;
        return `
          <div class="chart-bar-col" title="${esc(item.label)}: ${esc(valLabel)}">
            <div style="font-size:0.68rem;font-weight:700;color:var(--text-secondary);">${esc(valLabel)}</div>
            <div class="chart-bar-fill" style="height:${pct}px;"></div>
            <div class="chart-bar-label">${esc(item.label)}</div>
          </div>`;
      })
      .join('');
  }

  // 2. Search & Filter Applications
  async function searchApplications() {
    const q = document.getElementById('globalAdminSearch')?.value || '';
    const dateFrom = document.getElementById('filterDateFrom')?.value || '';
    const dateTo = document.getElementById('filterDateTo')?.value || '';
    const tbody = document.getElementById('applicationsTableBody');
    if (!tbody) return;

    try {
      const data = await apiGet({
        action: 'search_applications',
        q,
        status: currentFilterStatus,
        date_from: dateFrom,
        date_to: dateTo,
      });

      if (!data.success || !data.records || data.records.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:28px;color:var(--text-muted);">No matching applications found.</td></tr>';
        return;
      }

      tbody.innerHTML = data.records
        .map((r) => {
          const badgeClass = resolveBadgeClass(r.status);
          const dupBadge =
            Number(r.is_potential_duplicate) === 1 || Number(r.is_duplicate_utr) === 1
              ? '<span class="status-badge duplicate" style="margin-left:6px;">⚠ DUP FLAG</span>'
              : '';
          const allotInfo = r.certificate_number
            ? `<span style="color:var(--status-success);font-weight:700;">${esc(r.certificate_number)}</span>`
            : r.shares_allotted
            ? `${esc(r.shares_allotted)} Shares`
            : `${esc(parseFloat(Number(r.indicative_shares).toFixed(2)))} (Ind.)`;

          return `
            <tr style="cursor:pointer;" onclick="window.AdminApp.openInvestorProfile('${esc(r.application_id)}')">
              <td style="font-family:monospace;font-weight:700;">${esc(r.application_id)}${dupBadge}</td>
              <td style="font-weight:600;">${esc(r.full_name)}</td>
              <td><div>${esc(r.email)}</div><div style="font-size:0.75rem;color:var(--text-muted);">${esc(r.mobile)}</div></td>
              <td style="font-family:monospace;">${esc(r.pan_masked)}</td>
              <td style="font-weight:700;">${esc(r.amount_formatted)}</td>
              <td style="font-family:monospace;">${esc(r.utr_number || '—')}</td>
              <td><span class="status-badge ${badgeClass}">${esc(r.status)}</span></td>
              <td>${allotInfo}</td>
              <td style="font-size:0.78rem;color:var(--text-secondary);">${esc(r.created_at_ist)}</td>
              <td><button type="button" class="btn btn-secondary btn-sm">Inspect →</button></td>
            </tr>`;
        })
        .join('');
    } catch (e) {
      console.error(e);
    }
  }

  function resolveBadgeClass(status) {
    const s = String(status || '').toUpperCase();
    if (s.includes('VERIFIED') || s === 'APPROVED' || s === 'SHARES ALLOTTED' || s === 'CERTIFICATE GENERATED') return 'verified';
    if (s.includes('REJECTED') || s === 'DUPLICATE' || s === 'CANCELLED') return 'rejected';
    return 'pending';
  }

  // 3. Open Complete Investor Profile Drawer (Section 14 & 35)
  async function openInvestorProfile(appId) {
    const res = await apiGet({ action: 'get_investor_profile', application_id: appId });
    if (!res.success || !res.profile) {
      showToast(res.message || 'Unable to load investor profile.', 'error');
      return;
    }

    const p = res.profile;
    activeProfile = p;

    document.getElementById('drwAppIdTitle').textContent = `${p.application_id} (${p.full_name})`;
    document.getElementById('drwStatusBadge').textContent = p.status;

    // Anti-Fraud / Duplicate Warning Banner (Section 67)
    const warnBox = document.getElementById('drwDuplicateWarningBox');
    const warnings = [];
    if (res.other_utr_matches && res.other_utr_matches.length > 0) {
      const otherIds = res.other_utr_matches.map((m) => m.application_id).join(', ');
      warnings.push(`WARNING: This UTR (${p.utr_number}) has already been associated with another application (${otherIds}). Require administrator review.`);
    }
    if (p.duplicate_reason) {
      warnings.push(`Duplicate Detection Flag: ${p.duplicate_reason}`);
    }
    if (warnBox) {
      if (warnings.length > 0) {
        warnBox.style.display = 'block';
        warnBox.innerHTML = warnings.map((w) => `<div>⚠ <strong>${esc(w)}</strong></div>`).join('');
      } else {
        warnBox.style.display = 'none';
      }
    }

    // Section 1: Personal Info
    document.getElementById('drwPersonalGrid').innerHTML = [
      ['Investor Name', p.full_name],
      ['Investor ID', p.investor_id],
      ['Email', p.email],
      ['Mobile', p.mobile],
      ['Address', `${p.address}, ${p.city}, ${p.state} - ${p.pincode}`],
      ['PAN / Aadhaar (Masked)', `PAN: ${p.pan_masked} (${p.pan_decrypted}) | Aadhaar: ${p.aadhaar_masked}`],
    ]
      .map(([k, v]) => `<div class="kv-item"><div class="k">${esc(k)}</div><div class="v">${esc(v)}</div></div>`)
      .join('');

    // Section 2: Investment
    document.getElementById('drwInvestmentGrid').innerHTML = [
      ['Investment Amount', formatINR(p.investment_amount)],
      ['Indicative Shares', `${parseFloat(Number(p.indicative_shares).toFixed(4))} Shares`],
      ['Issue Price / Share', formatINR(p.configured_issue_price)],
      ['Configured Valuation', formatINR(p.configured_valuation)],
      ['Indicative Ownership %', `${parseFloat(Number(p.indicative_ownership_pct).toFixed(6))}%`],
      ['Created Date (IST)', p.created_at],
    ]
      .map(([k, v]) => `<div class="kv-item"><div class="k">${esc(k)}</div><div class="v">${esc(v)}</div></div>`)
      .join('');

    // Section 3: Payment & Proof Files (Section 35)
    document.getElementById('drwPaymentGrid').innerHTML = [
      ['UTR / Reference Number', p.utr_number || '—'],
      ['Payment Date', p.payment_date || '—'],
      ['Amount Paid', formatINR(p.amount_paid || p.investment_amount)],
      ['Payment Method', p.payment_method || 'Bank Transfer'],
      ['Remitter Bank / UPI', p.sender_bank_or_upi || '—'],
      ['Verification Status', p.payment_verification_status || 'UNDER_VERIFICATION'],
    ]
      .map(([k, v]) => `<div class="kv-item"><div class="k">${esc(k)}</div><div class="v">${esc(v)}</div></div>`)
      .join('');

    const filesBox = document.getElementById('drwPaymentFilesBox');
    if (filesBox) {
      const docs = res.documents || [];
      if (docs.length === 0) {
        filesBox.innerHTML = '<div style="font-size:0.82rem;color:var(--text-muted);">No payment proof documents found.</div>';
      } else {
        filesBox.innerHTML = docs
          .map(
            (d) => `
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;padding:12px;border-radius:10px;background:var(--bg-card-solid);border:1px solid var(--border-subtle);margin-top:8px;gap:12px;">
              <div style="font-size:0.82rem;">
                <div><strong>Payment Proof File:</strong> <code>${esc(d.stored_filename)}</code> (${esc(d.verification_status)})</div>
                <div style="color:var(--text-muted);">UTR: <strong>${esc(d.utr_number)}</strong> • Uploaded: ${esc(d.uploaded_at_ist)} • Application: ${esc(p.application_id)}</div>
              </div>
              <div style="display:flex;gap:8px;">
                <a href="../api/admin_api.php?action=view_payment_proof&doc_id=${d.id}" target="_blank" class="btn btn-secondary btn-sm">[View]</a>
                <a href="../api/admin_api.php?action=view_payment_proof&doc_id=${d.id}&download=1" class="btn btn-secondary btn-sm">[Download]</a>
                <a href="../api/admin_api.php?action=view_payment_proof&doc_id=${d.id}" target="_blank" class="btn btn-primary btn-sm">[Open]</a>
              </div>
            </div>`
          )
          .join('');
      }
    }

    // Editable fields & notes
    document.getElementById('drwEditName').value = p.full_name || '';
    document.getElementById('drwEditUtr').value = p.utr_number || '';
    document.getElementById('drwAdminNoteInput').value = '';
    document.getElementById('drwExistingNotes').textContent = p.admin_notes ? `Saved Notes: ${p.admin_notes}` : 'No admin notes yet.';

    // Section 5: Share Allocation Inputs
    document.getElementById('allocSharesInput').value = p.shares_allotted || Math.floor(Number(p.indicative_shares) || 1);
    document.getElementById('allocDateInput').value = p.allotment_date || new Date().toISOString().split('T')[0];
    document.getElementById('allocFolioInput').value = p.folio_number || '';
    document.getElementById('allocBoardRefInput').value = p.board_resolution_ref || '';

    // Section 6: Certificate Info
    const certInfo = document.getElementById('drwCertificateInfoBox');
    const dlBtn = document.getElementById('drwDownloadCertBtn');
    if (p.certificate_number) {
      certInfo.innerHTML = `
        <strong>Certificate Number:</strong> <code>${esc(p.certificate_number)}</code> |
        <strong>Status:</strong> <span class="status-badge verified">${esc(p.certificate_status)}</span> |
        <strong>Generated:</strong> ${esc(p.cert_generated_at || '—')} |
        <a href="../verify.php?id=${encodeURIComponent(p.certificate_number)}" target="_blank" style="color:var(--accent-red-hover);">Open Public Verification Page ↗</a>
      `;
      if (dlBtn) {
        dlBtn.style.display = 'inline-flex';
        dlBtn.href = `../api/certificate_download.php?cert_no=${encodeURIComponent(p.certificate_number)}&download=1`;
      }
    } else {
      certInfo.textContent = 'No Share Certificate generated yet. Record Share Allotment in Section 5 above, then click "Generate Share Certificate".';
      if (dlBtn) dlBtn.style.display = 'none';
    }

    // Audit Logs
    const auditBox = document.getElementById('drwAuditList');
    if (auditBox) {
      auditBox.innerHTML = (res.audit_logs || [])
        .map(
          (a) =>
            `<div style="padding:6px 0;border-bottom:1px solid var(--border-subtle);"><strong>${esc(a.action)}</strong> by ${esc(a.admin_email)} (${esc(a.created_at_ist)}) ${a.notes ? '— ' + esc(a.notes) : ''}</div>`
        )
        .join('');
    }

    document.getElementById('investorDrawerBackdrop').classList.add('open');
  }

  function closeProfileDrawer() {
    document.getElementById('investorDrawerBackdrop').classList.remove('open');
  }

  // 4. Confirmation Dialog & Workflow Execution (Section 59)
  function promptWorkflow(subAction, actionLabel) {
    if (!activeProfile) return;
    document.getElementById('confActionName').textContent = actionLabel;
    document.getElementById('confAppId').textContent = activeProfile.application_id;
    document.getElementById('confInvestorName').textContent = activeProfile.full_name;
    document.getElementById('confWarningText').textContent =
      'This administrative transition will be permanently recorded in the Audit Trail and trigger an investor notification.';
    document.getElementById('confNotesInput').value = '';

    pendingConfirmCallback = async () => {
      const notes = document.getElementById('confNotesInput').value;
      const res = await apiPost('workflow_action', {
        application_id: activeProfile.application_id,
        sub_action: subAction,
        notes,
      });
      if (res.success) {
        showToast(res.message, 'success');
        closeConfirmModal();
        await openInvestorProfile(activeProfile.application_id);
        await searchApplications();
        await loadDashboard();
      } else {
        showToast(res.message || 'Workflow update failed.', 'error');
      }
    };

    document.getElementById('adminConfirmModal').classList.add('open');
  }

  function promptCertificateGeneration(isRegen) {
    if (!activeProfile) return;
    const label = isRegen ? 'Regenerate Share Certificate' : 'Generate Official Share Certificate';
    document.getElementById('confActionName').textContent = label;
    document.getElementById('confAppId').textContent = activeProfile.application_id;
    document.getElementById('confInvestorName').textContent = activeProfile.full_name;
    document.getElementById('confWarningText').textContent =
      'Only allowed when Payment is Verified, Application is Approved, and Actual Share Allotment is recorded. Generates PDF + QR and emails the investor.';
    document.getElementById('confNotesInput').value = '';

    pendingConfirmCallback = async () => {
      const res = await apiPost('generate_certificate', {
        application_id: activeProfile.application_id,
      });
      if (res.success) {
        showToast(res.message, 'success');
        closeConfirmModal();
        await openInvestorProfile(activeProfile.application_id);
        await searchApplications();
        await loadDashboard();
      } else {
        showToast(res.message || 'Certificate generation failed.', 'error');
      }
    };

    document.getElementById('adminConfirmModal').classList.add('open');
  }

  function closeConfirmModal() {
    document.getElementById('adminConfirmModal').classList.remove('open');
    pendingConfirmCallback = null;
  }

  async function saveNoteAndEdits() {
    if (!activeProfile) return;
    const res = await apiPost('save_admin_note', {
      application_id: activeProfile.application_id,
      admin_note: document.getElementById('drwAdminNoteInput').value,
      full_name: document.getElementById('drwEditName').value,
      mobile: activeProfile.mobile,
      utr_number: document.getElementById('drwEditUtr').value,
    });
    if (res.success) {
      showToast(res.message, 'success');
      await openInvestorProfile(activeProfile.application_id);
      await searchApplications();
    } else {
      showToast(res.message || 'Failed to save changes.', 'error');
    }
  }

  async function recordShareAllotment() {
    if (!activeProfile) return;
    const res = await apiPost('record_share_allotment', {
      application_id: activeProfile.application_id,
      shares_allotted: document.getElementById('allocSharesInput').value,
      allotment_date: document.getElementById('allocDateInput').value,
      folio_number: document.getElementById('allocFolioInput').value,
      board_resolution_ref: document.getElementById('allocBoardRefInput').value,
    });
    if (res.success) {
      showToast(res.message, 'success');
      await openInvestorProfile(activeProfile.application_id);
      await searchApplications();
      await loadDashboard();
    } else {
      showToast(res.message || 'Could not record allotment.', 'error');
    }
  }

  async function resendTemplateEmail(templateKey) {
    if (!activeProfile) return;
    const res = await apiPost('resend_email', {
      application_id: activeProfile.application_id,
      template_key: templateKey,
    });
    showToast(res.message || 'Email dispatched.', res.success ? 'success' : 'error');
  }

  // 5. Settings, Assets, Logs & Exports
  async function loadSettings() {
    const res = await apiGet({ action: 'get_settings' });
    if (!res.success || !res.settings) return;
    const s = res.settings;
    document.querySelectorAll('[data-setting]').forEach((el) => {
      const key = el.getAttribute('data-setting');
      if (s[key] !== undefined) {
        el.value = s[key];
      }
    });
    if (s.payment_qr_image) {
      const qrEl = document.getElementById('adminPreviewPaymentQr');
      if (qrEl) qrEl.src = '../' + s.payment_qr_image;
    }
  }

  async function loadLogs(type = 'audit') {
    const res = await apiGet({ action: 'get_logs', log_type: type });
    if (!res.success) return;

    if (type === 'email') {
      const tbody = document.getElementById('emailLogsTableBody');
      if (!tbody) return;
      tbody.innerHTML = (res.logs || [])
        .map(
          (l) => `
          <tr>
            <td>#${l.id}</td>
            <td style="font-family:monospace;">${esc(l.application_id || '—')}</td>
            <td>${esc(l.recipient_email)}</td>
            <td><code>${esc(l.template_key)}</code></td>
            <td>${esc(l.subject)}</td>
            <td><span class="status-badge ${l.status === 'FAILED' ? 'rejected' : 'verified'}">${esc(l.status)}</span></td>
            <td>${esc(l.sent_at_ist)}</td>
          </tr>`
        )
        .join('');
    } else {
      const tbody = document.getElementById('auditLogsTableBody');
      if (!tbody) return;
      tbody.innerHTML = (res.logs || [])
        .map(
          (l) => `
          <tr>
            <td>#${l.id}</td>
            <td>${esc(l.admin_email)}</td>
            <td style="font-weight:700;">${esc(l.action)}</td>
            <td><code>${esc(l.entity_type)}</code></td>
            <td style="font-family:monospace;">${esc(l.entity_id)}</td>
            <td style="font-size:0.78rem;color:var(--text-secondary);">${esc(l.old_value ? l.old_value + ' → ' + l.new_value : l.notes || '—')}</td>
            <td style="font-family:monospace;">${esc(l.ip_address)}</td>
            <td>${esc(l.created_at_ist)}</td>
          </tr>`
        )
        .join('');
    }
  }

  function exportReport(format) {
    const rType = document.getElementById('expReportType')?.value || 'application';
    const status = document.getElementById('expStatusFilter')?.value || 'ALL';
    const df = document.getElementById('expDateFrom')?.value || '';
    const dt = document.getElementById('expDateTo')?.value || '';
    const qs = new URLSearchParams({
      action: 'export_report',
      report_type: rType,
      format,
      status,
      date_from: df,
      date_to: dt,
    }).toString();
    window.open(`../api/admin_api.php?${qs}`, '_blank');
  }

  // Initialize Events
  document.addEventListener('DOMContentLoaded', () => {
    // Theme toggle
    const savedTheme = localStorage.getItem('corelbase_theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
    const tBtn = document.getElementById('adminThemeToggle');
    if (tBtn) {
      tBtn.addEventListener('click', () => {
        const cur = document.documentElement.getAttribute('data-theme') || 'dark';
        const nxt = cur === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', nxt);
        localStorage.setItem('corelbase_theme', nxt);
        document.getElementById('adminThemeIcon').textContent = nxt === 'light' ? '☀' : '☾';
        document.getElementById('adminThemeLabel').textContent = nxt === 'light' ? 'Light' : 'Dark';
      });
    }

    // Sidebar Mobile Toggle
    const sbToggle = document.getElementById('adminSidebarToggle');
    const sb = document.getElementById('adminSidebar');
    if (sbToggle && sb) {
      sbToggle.addEventListener('click', () => sb.classList.toggle('open'));
    }

    // Tab switching
    document.querySelectorAll('.sidebar-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.sidebar-btn').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        const tab = btn.getAttribute('data-tab');
        document.querySelectorAll('.admin-tab-view').forEach((v) => {
          v.classList.toggle('active', v.id === `tab-${tab}`);
        });
        if (tab === 'applications') searchApplications();
        if (tab === 'capital' || tab === 'payment_settings' || tab === 'smtp') loadSettings();
        if (tab === 'smtp') loadLogs('email');
        if (tab === 'audit') loadLogs('audit');
        if (sb) sb.classList.remove('open');
      });
    });

    // Global instant search
    const searchInput = document.getElementById('globalAdminSearch');
    if (searchInput) {
      let timer = null;
      searchInput.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
          document.querySelector('.sidebar-btn[data-tab="applications"]')?.click();
          searchApplications();
        }, 240);
      });
    }

    // Status Filter Pills
    document.querySelectorAll('#statusFilterStrip .filter-pill').forEach((pill) => {
      pill.addEventListener('click', () => {
        document.querySelectorAll('#statusFilterStrip .filter-pill').forEach((p) => p.classList.remove('active'));
        pill.classList.add('active');
        currentFilterStatus = pill.getAttribute('data-status') || 'ALL';
        searchApplications();
      });
    });

    // Settings Forms
    ['capitalConfigForm', 'paymentConfigForm', 'smtpConfigForm'].forEach((formId) => {
      const f = document.getElementById(formId);
      if (f) {
        f.addEventListener('submit', async (e) => {
          e.preventDefault();
          const res = await apiPost('save_settings', new FormData(f));
          showToast(res.message || 'Settings saved.', res.success ? 'success' : 'error');
          loadSettings();
        });
      }
    });

    // Asset Upload Forms
    document.querySelectorAll('.asset-upload-form').forEach((form) => {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(form);
        fd.set('asset_key', form.getAttribute('data-asset-key') || '');
        const res = await apiPost('upload_company_asset', fd);
        if (res.success) {
          showToast(res.message, 'success');
          loadSettings();
        } else {
          showToast(res.message || 'Upload failed.', 'error');
        }
      });
    });

    // Test SMTP Button
    const smtpBtn = document.getElementById('sendTestSmtpBtn');
    if (smtpBtn) {
      smtpBtn.addEventListener('click', async () => {
        const email = document.getElementById('testSmtpEmailInput')?.value || '';
        const box = document.getElementById('smtpTestResultBox');
        if (box) box.textContent = 'Testing SMTP connection...';
        const res = await apiPost('test_smtp', { test_email: email });
        if (box) box.textContent = res.message || '';
        showToast(res.message || 'SMTP test completed.', res.success ? 'success' : 'error');
        loadLogs('email');
      });
    }

    // Confirm Modal Execute button
    const confBtn = document.getElementById('confExecuteBtn');
    if (confBtn) {
      confBtn.addEventListener('click', () => {
        if (typeof pendingConfirmCallback === 'function') pendingConfirmCallback();
      });
    }

    loadDashboard();
    searchApplications();
  });

  window.AdminApp = {
    loadDashboard,
    searchApplications,
    openInvestorProfile,
    closeProfileDrawer,
    promptWorkflow,
    promptCertificateGeneration,
    closeConfirmModal,
    saveNoteAndEdits,
    recordShareAllotment,
    resendTemplateEmail,
    loadLogs,
    exportReport,
  };
})();
