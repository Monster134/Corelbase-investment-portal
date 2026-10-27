/**
 * CORELBASE PRIVATE LIMITED — PUBLIC FRONTEND APPLICATION LOGIC (js/app.js)
 * -----------------------------------------------------------------------------
 * Handles:
 * - Dark / Light Theme toggle persisted via localStorage (Default: Dark)
 * - Dynamic configuration hydration from `api/public_config.php`
 * - Real-time Indicative Share & Ownership Calculator
 * - 7-Step Investor Registration & Application Wizard with client + server validation
 * - Live PAN & Aadhaar masking preview
 * - Clipboard Copy buttons & QR Download
 * - Legal & Privacy Policy modals (Privacy, Terms, Disclaimer, Refund, KYC)
 */

(function () {
  'use strict';

  // Default portal configuration (hydrates dynamically from api/public_config.php)
  const PortalState = {
    config: {
      company_name: 'Corelbase Private Limited',
      company_cin: 'U62099UP2026PTC250888',
      company_gstin: '09AAOCC1033C1Z1',
      company_registered_office: 'HOUSE NO 173, BHARROH, GORAKHPUR, UTTAR PRADESH, Bharroh, Gola, Gorakhpur - 273408, Uttar Pradesh',
      company_email: 'support@corelbase.com',
      target_investment: 1000000,
      company_valuation: 100000000,
      min_investment: 5000,
      max_investment: 100000,
      face_value_per_share: 10,
      issue_price_per_share: 1000,
      premium_per_share: 990,
      share_class_default: 'Equity Shares',
      bank_name: 'State Bank of India',
      account_name: 'Corelbase Private Limited',
      account_number: '45516065276',
      ifsc_code: 'SBIN0001512',
      upi_id: '[CONFIGURE FROM ADMIN PANEL]',
      payment_qr_image: 'assets/brand/payment_qr_placeholder.png',
      max_upload_size_mb: 5,
    },
    currentStep: 1,
    csrfToken: '',
  };

  // Format INR currency in Indian numbering style
  function formatINR(value) {
    const num = Number(value) || 0;
    return '₹' + num.toLocaleString('en-IN', { maximumFractionDigits: 2 });
  }

  // Toast helper
  function showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = 'toast';
    if (type === 'success') toast.style.borderLeftColor = '#10b981';
    if (type === 'error') toast.style.borderLeftColor = '#ef4444';
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(() => {
      toast.style.opacity = '0';
      setTimeout(() => toast.remove(), 220);
    }, 3800);
  }
  window.showToast = showToast;

  // 1. Dark / Light Theme Initialization & Toggle (Section 40)
  function initTheme() {
    const saved = localStorage.getItem('corelbase_theme') || 'dark';
    applyTheme(saved);

    const btn = document.getElementById('themeToggleBtn');
    if (btn) {
      btn.addEventListener('click', () => {
        const current = document.documentElement.getAttribute('data-theme') || 'dark';
        const next = current === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        localStorage.setItem('corelbase_theme', next);
      });
    }
  }

  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    const icon = document.getElementById('themeIcon');
    const label = document.getElementById('themeLabel');
    if (icon && label) {
      if (theme === 'light') {
        icon.textContent = '☀';
        label.textContent = 'Light';
      } else {
        icon.textContent = '☾';
        label.textContent = 'Dark';
      }
    }
  }

  // 2. Mobile Menu Toggle
  function initMobileNav() {
    const btn = document.getElementById('mobileMenuBtn');
    const links = document.getElementById('mainNavLinks');
    if (btn && links) {
      btn.addEventListener('click', () => {
        const isOpen = links.classList.toggle('open');
        btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      });
      links.querySelectorAll('a').forEach((a) => {
        a.addEventListener('click', () => links.classList.remove('open'));
      });
    }
  }

  // 3. Fetch Public Configuration from Backend API
  async function loadPublicConfig() {
    try {
      const res = await fetch('api/public_config.php', { credentials: 'same-origin' });
      if (!res.ok) return;
      const json = await res.json();
      if (json && json.success && json.config) {
        Object.assign(PortalState.config, json.config);
        if (json.csrf_token) {
          PortalState.csrfToken = json.csrf_token;
          const csrfEl = document.getElementById('formCsrfToken');
          if (csrfEl) csrfEl.value = json.csrf_token;
        }
        renderConfigToDom();
      }
    } catch (e) {
      // Offline / static fallback works seamlessly with defaults
    }
  }

  function renderConfigToDom() {
    const cfg = PortalState.config;
    document.querySelectorAll('[data-cfg]').forEach((el) => {
      const key = el.getAttribute('data-cfg');
      if (cfg[key] !== undefined && cfg[key] !== '') {
        el.textContent = cfg[key];
      }
    });

    const minInv = Number(cfg.min_investment) || 5000;
    const maxInv = Number(cfg.max_investment) || 100000;
    const targetInv = Number(cfg.target_investment) || 1000000;
    const valuation = Number(cfg.company_valuation) || 100000000;
    const issuePrice = Number(cfg.issue_price_per_share) || 1000;
    const faceValue = Number(cfg.face_value_per_share) || 10;
    const premium = Math.max(0, issuePrice - faceValue);

    const setTxt = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.textContent = val;
    };

    setTxt('heroTargetRound', formatINR(targetInv));
    setTxt('heroValuation', formatINR(valuation));
    setTxt('heroMinInv', formatINR(minInv));
    setTxt('heroMaxInv', formatINR(maxInv));
    setTxt('heroIssuePrice', formatINR(issuePrice) + ' / Share');
    const roundEq = ((targetInv / valuation) * 100).toFixed(2);
    setTxt('heroRoundEquity', '~' + roundEq + '% Equity');

    setTxt('oppTarget', formatINR(targetInv));
    setTxt('oppMin', formatINR(minInv));
    setTxt('oppMax', formatINR(maxInv));
    setTxt('termIssuePrice', formatINR(issuePrice) + ' / Share');
    setTxt('termFaceValue', formatINR(faceValue) + ' / Share');
    setTxt('termPremium', formatINR(premium) + ' / Share');
    setTxt('lblMaxUploadMb', cfg.max_upload_size_mb || '5');

    // Render public Share Capital items based on admin visibility flags
    const capContainer = document.getElementById('publicCapitalContainer');
    if (capContainer) {
      const items = [
        ['authorized_capital', 'authorized_capital_public', 'Authorized Capital', true],
        ['paid_up_capital', 'paid_up_capital_public', 'Paid-Up Capital', true],
        ['issued_capital', 'issued_capital_public', 'Issued Capital', true],
        ['subscribed_capital', 'subscribed_capital_public', 'Subscribed Capital', true],
        ['authorized_shares', 'authorized_shares_public', 'Authorized Shares', false],
        ['total_issued_shares', 'total_issued_shares_public', 'Total Issued Shares', false],
        ['available_shares', 'available_shares_public', 'Indicative Round Shares', false],
      ];
      let html = '';
      items.forEach(([valKey, flagKey, label, isCurrency]) => {
        if (String(cfg[flagKey] || '0') === '1' && cfg[valKey] !== undefined) {
          const formatted = isCurrency
            ? formatINR(cfg[valKey])
            : Number(cfg[valKey]).toLocaleString('en-IN') + ' Shares';
          html += `<div class="bank-detail-row"><span class="bank-detail-label">${label}</span><span class="bank-detail-val">${formatted}</span></div>`;
        }
      });
      if (html !== '') capContainer.innerHTML = html;
    }

    // Update QR images if configured
    if (cfg.payment_qr_image) {
      const qr1 = document.getElementById('publicQrImage');
      const qr2 = document.getElementById('wizQrImage');
      const dlBtn = document.getElementById('downloadQrBtn');
      if (qr1) qr1.src = cfg.payment_qr_image;
      if (qr2) qr2.src = cfg.payment_qr_image;
      if (dlBtn) dlBtn.href = cfg.payment_qr_image;
    }

    updateCalculatorOutput();
    updateWizardCalculation();
  }

  // 4. Interactive Investment Calculator (Sections 2, 3, 24)
  function updateCalculatorOutput() {
    const input = document.getElementById('calcAmountInput');
    const slider = document.getElementById('calcAmountSlider');
    const warn = document.getElementById('calcValidationWarning');
    if (!input) return;

    const amount = Number(input.value) || 0;
    const cfg = PortalState.config;
    const minInv = Number(cfg.min_investment) || 5000;
    const maxInv = Number(cfg.max_investment) || 100000;
    const valuation = Math.max(1, Number(cfg.company_valuation) || 100000000);
    const issuePrice = Math.max(1, Number(cfg.issue_price_per_share) || 1000);
    const faceVal = Number(cfg.face_value_per_share) || 10;
    const premium = Math.max(0, issuePrice - faceVal);

    if (slider && amount >= minInv && amount <= maxInv) {
      slider.value = String(amount);
    }

    if (warn) {
      warn.style.display = amount < minInv || amount > maxInv ? 'block' : 'none';
      warn.textContent = `Investment amount must be between ${formatINR(minInv)} and ${formatINR(maxInv)}.`;
    }

    const shares = amount / issuePrice;
    const ownershipPct = (amount / valuation) * 100;

    const setTxt = (id, v) => {
      const el = document.getElementById(id);
      if (el) el.textContent = v;
    };

    setTxt('calcOutShares', Number.isInteger(shares) ? shares.toLocaleString('en-IN') : shares.toFixed(2));
    setTxt('calcOutOwnership', parseFloat(ownershipPct.toFixed(6)) + '%');
    setTxt('calcOutIssuePrice', formatINR(issuePrice));
    setTxt('calcOutFaceVal', faceVal);
    setTxt('calcOutPremium', premium);
    setTxt('calcOutInvestment', formatINR(amount));
  }

  function initCalculator() {
    const input = document.getElementById('calcAmountInput');
    const slider = document.getElementById('calcAmountSlider');
    const resetBtn = document.getElementById('calcResetBtn');
    const applyBtn = document.getElementById('calcApplyNowBtn');

    if (input) {
      input.addEventListener('input', updateCalculatorOutput);
    }
    if (slider) {
      slider.addEventListener('input', () => {
        if (input) input.value = slider.value;
        updateCalculatorOutput();
      });
    }
    document.querySelectorAll('.preset-pill:not(.wiz-amt-pill)').forEach((pill) => {
      pill.addEventListener('click', () => {
        document.querySelectorAll('.preset-pill:not(.wiz-amt-pill)').forEach((p) => p.classList.remove('active'));
        pill.classList.add('active');
        const amt = pill.getAttribute('data-amt');
        if (input) input.value = amt;
        updateCalculatorOutput();
      });
    });

    if (resetBtn) {
      resetBtn.addEventListener('click', () => {
        if (input) input.value = '25000';
        updateCalculatorOutput();
      });
    }

    if (applyBtn) {
      applyBtn.addEventListener('click', () => {
        const wizAmt = document.getElementById('investment_amount');
        const paidAmt = document.getElementById('amount_paid');
        if (wizAmt && input) {
          wizAmt.value = input.value;
          if (paidAmt) paidAmt.value = input.value;
          updateWizardCalculation();
        }
        const applySection = document.getElementById('apply');
        if (applySection) applySection.scrollIntoView({ behavior: 'smooth' });
      });
    }
  }

  // 5. Wizard Live Calculation & KYC Masking Preview
  function updateWizardCalculation() {
    const wizInput = document.getElementById('investment_amount');
    const paidInput = document.getElementById('amount_paid');
    if (!wizInput) return;

    const amt = Number(wizInput.value) || 0;
    const cfg = PortalState.config;
    const valuation = Math.max(1, Number(cfg.company_valuation) || 100000000);
    const issuePrice = Math.max(1, Number(cfg.issue_price_per_share) || 1000);
    const faceVal = Number(cfg.face_value_per_share) || 10;
    const premium = Math.max(0, issuePrice - faceVal);

    const shares = amt / issuePrice;
    const ownership = (amt / valuation) * 100;

    const setTxt = (id, v) => {
      const el = document.getElementById(id);
      if (el) el.textContent = v;
    };

    setTxt('wizLiveAmount', formatINR(amt));
    setTxt('wizLiveShares', Number.isInteger(shares) ? shares.toLocaleString('en-IN') : shares.toFixed(2));
    setTxt('wizLiveOwnership', parseFloat(ownership.toFixed(6)) + '%');
    setTxt('wizLivePriceBreakdown', `${formatINR(issuePrice)} (${formatINR(faceVal)} + ${formatINR(premium)})`);
    setTxt('step4PayAmountLabel', formatINR(amt));
    if (paidInput) paidInput.value = String(amt);
  }

  function initWizardListeners() {
    const wizAmt = document.getElementById('investment_amount');
    if (wizAmt) {
      wizAmt.addEventListener('input', updateWizardCalculation);
    }
    document.querySelectorAll('.wiz-amt-pill').forEach((pill) => {
      pill.addEventListener('click', () => {
        document.querySelectorAll('.wiz-amt-pill').forEach((p) => p.classList.remove('active'));
        pill.classList.add('active');
        if (wizAmt) wizAmt.value = pill.getAttribute('data-amt');
        updateWizardCalculation();
      });
    });

    // Live PAN Mask Preview
    const panInput = document.getElementById('pan_number');
    const panMaskEl = document.getElementById('panMaskPreview');
    if (panInput && panMaskEl) {
      panInput.addEventListener('input', () => {
        const v = panInput.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        panInput.value = v;
        panMaskEl.textContent = v.length === 10 ? v.slice(0, 5) + '****' + v.slice(9, 10) : 'ABCDE****F';
      });
    }

    // Live Aadhaar Mask Preview
    const aadhaarInput = document.getElementById('aadhaar_number');
    const aadhaarMaskEl = document.getElementById('aadhaarMaskPreview');
    if (aadhaarInput && aadhaarMaskEl) {
      aadhaarInput.addEventListener('input', () => {
        const digits = aadhaarInput.value.replace(/\D/g, '').slice(0, 12);
        aadhaarInput.value = digits;
        aadhaarMaskEl.textContent = digits.length >= 4 ? '********' + digits.slice(-4) : '********1234';
      });
    }

    // Default payment date to today
    const payDateInput = document.getElementById('payment_date');
    if (payDateInput && !payDateInput.value) {
      payDateInput.value = new Date().toISOString().split('T')[0];
    }

    // File upload preview
    const fileInput = document.getElementById('payment_screenshot');
    const filePreview = document.getElementById('fileSelectedPreview');
    if (fileInput && filePreview) {
      fileInput.addEventListener('change', () => {
        const file = fileInput.files && fileInput.files[0];
        if (!file) {
          filePreview.textContent = 'No file chosen yet.';
          return;
        }
        const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
        filePreview.textContent = `Selected: ${file.name} (${sizeMb} MB)`;
      });
    }
  }

  // 6. Step Validation & Navigation (Section 25)
  function validateStep(step) {
    const getVal = (id) => (document.getElementById(id)?.value || '').trim();
    const cfg = PortalState.config;
    const minInv = Number(cfg.min_investment) || 5000;
    const maxInv = Number(cfg.max_investment) || 100000;

    if (step === 1) {
      if (getVal('full_name').length < 3) {
        showToast('Please enter your full legal name.', 'error');
        return false;
      }
      const email = getVal('email');
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showToast('Invalid email address.', 'error');
        return false;
      }
      const mob = getVal('mobile').replace(/\D/g, '');
      if (!/^[6-9]\d{9}$/.test(mob)) {
        showToast('Please enter a valid 10-digit Indian mobile number.', 'error');
        return false;
      }
      if (!getVal('dob') || !getVal('address') || !getVal('city') || !getVal('state') || !getVal('pincode')) {
        showToast('Please complete all mandatory address and date of birth fields.', 'error');
        return false;
      }
    } else if (step === 2) {
      const pan = getVal('pan_number').toUpperCase();
      if (!/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/.test(pan)) {
        showToast('Please enter a valid 10-character PAN number (e.g., ABCDE1234F).', 'error');
        return false;
      }
      const aadhaar = getVal('aadhaar_number').replace(/\D/g, '');
      if (!/^[2-9]\d{11}$/.test(aadhaar)) {
        showToast('Please enter a valid 12-digit Aadhaar number.', 'error');
        return false;
      }
    } else if (step === 3) {
      const amt = Number(getVal('investment_amount'));
      if (!amt || amt < minInv || amt > maxInv) {
        showToast(`Investment amount must be between ${formatINR(minInv)} and ${formatINR(maxInv)}.`, 'error');
        return false;
      }
    } else if (step === 5) {
      if (!getVal('payment_date')) {
        showToast('Payment date is required.', 'error');
        return false;
      }
      if (getVal('utr_number').length < 6) {
        showToast('UTR / transaction reference number is required.', 'error');
        return false;
      }
      if (!getVal('sender_bank_or_upi')) {
        showToast('Please specify the bank or UPI app used for transfer.', 'error');
        return false;
      }
      const fileInput = document.getElementById('payment_screenshot');
      if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        showToast('Payment proof screenshot is required.', 'error');
        return false;
      }
      populateReviewStep();
    }
    return true;
  }

  function populateReviewStep() {
    const getVal = (id) => (document.getElementById(id)?.value || '').trim();
    const pan = getVal('pan_number').toUpperCase();
    const aadhaar = getVal('aadhaar_number').replace(/\D/g, '');
    const amt = Number(getVal('investment_amount')) || 0;
    const issuePrice = Math.max(1, Number(PortalState.config.issue_price_per_share) || 1000);
    const valuation = Math.max(1, Number(PortalState.config.company_valuation) || 100000000);
    const shares = amt / issuePrice;
    const ownership = (amt / valuation) * 100;
    const fileInput = document.getElementById('payment_screenshot');
    const fileName = fileInput?.files?.[0]?.name || 'Attached';

    const summaryEl = document.getElementById('applicationReviewSummary');
    if (!summaryEl) return;

    const items = [
      ['Applicant Name', getVal('full_name')],
      ['Email & Mobile', `${getVal('email')} | ${getVal('mobile')}`],
      ['Location', `${getVal('city')}, ${getVal('state')} - ${getVal('pincode')}`],
      ['PAN & Aadhaar (Masked)', `${pan.slice(0, 5)}****${pan.slice(-1)} | ********${aadhaar.slice(-4)}`],
      ['Investment Amount', formatINR(amt)],
      ['Indicative Shares & Ownership', `${shares} Shares (~${parseFloat(ownership.toFixed(6))}%) — Indicative Only`],
      ['Payment Method & Date', `${getVal('payment_method')} on ${getVal('payment_date')}`],
      ['UTR / Reference & Proof', `UTR: ${getVal('utr_number')} (${fileName})`],
    ];

    summaryEl.innerHTML = items
      .map(
        ([label, val]) => `
        <div class="calc-stat-box">
          <div class="metric-label">${label}</div>
          <div style="font-weight:700;font-size:0.95rem;word-break:break-word;">${val.replace(/</g, '&lt;')}</div>
        </div>`
      )
      .join('');
  }

  function goToWizardStep(targetStep) {
    PortalState.currentStep = targetStep;
    document.querySelectorAll('.wizard-pane').forEach((pane) => {
      pane.classList.toggle('active', Number(pane.getAttribute('data-pane')) === targetStep);
    });
    document.querySelectorAll('.wizard-step-tab').forEach((tab) => {
      const s = Number(tab.getAttribute('data-step'));
      tab.classList.toggle('active', s === targetStep);
      tab.classList.toggle('completed', s < targetStep);
    });
  }

  window.goToWizardStep = goToWizardStep;
  window.nextWizardStep = function (currentStep) {
    if (!validateStep(currentStep)) return;
    goToWizardStep(currentStep + 1);
  };
  window.prevWizardStep = function (currentStep) {
    if (currentStep > 1) goToWizardStep(currentStep - 1);
  };
  window.resetApplicationWizard = function () {
    const form = document.getElementById('investorApplicationForm');
    if (form) form.reset();
    goToWizardStep(1);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  // 7. Submit Application to `api/apply.php`
  function initFormSubmission() {
    const form = document.getElementById('investorApplicationForm');
    if (!form) return;

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const decl = document.getElementById('declaration_accepted');
      if (!decl || !decl.checked) {
        showToast('Please accept the mandatory investor declaration before submitting.', 'error');
        return;
      }

      const submitBtn = document.getElementById('submitApplicationBtn');
      const originalText = submitBtn ? submitBtn.textContent : 'Submit';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting Application & Encrypting KYC...';
      }

      try {
        const formData = new FormData(form);
        const res = await fetch('api/apply.php', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
        });
        const json = await res.json();

        if (!json.success) {
          showToast(json.message || 'Unable to process your application. Please try again.', 'error');
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
          }
          return;
        }

        // Populate Step 7 Success Card
        const appId = json.application_id || 'CB-2026-000001';
        const invId = json.investor_id || appId;
        const email = document.getElementById('email')?.value || '';
        const amt = Number(document.getElementById('investment_amount')?.value || 25000);

        const setTxt = (id, v) => {
          const el = document.getElementById(id);
          if (el) el.textContent = v;
        };
        setTxt('succAppId', appId);
        setTxt('succInvestorId', invId);
        setTxt('succEmailDisplay', email);
        setTxt('succAmount', formatINR(amt));

        const trackBtn = document.getElementById('succTrackBtn');
        if (trackBtn) trackBtn.href = `track.php?app_id=${encodeURIComponent(appId)}&email=${encodeURIComponent(email)}`;
        const ackBtn = document.getElementById('succAckPdfBtn');
        if (ackBtn) ackBtn.href = `api/acknowledgement.php?app_id=${encodeURIComponent(appId)}&email=${encodeURIComponent(email)}`;

        goToWizardStep(7);
        showToast('Application submitted successfully! ID: ' + appId, 'success');
      } catch (err) {
        showToast('Network error while submitting application. Please check your connection.', 'error');
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = originalText;
        }
      }
    });
  }

  // 8. Copy Buttons & FAQ Accordion
  function initCopyAndFaq() {
    document.querySelectorAll('.copy-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        let text = btn.getAttribute('data-copy-text') || '';
        const targetKey = btn.getAttribute('data-copy-target');
        if (targetKey && PortalState.config[targetKey]) {
          text = PortalState.config[targetKey];
        }
        if (!text) return;
        navigator.clipboard?.writeText(text).then(() => {
          showToast(`Copied to clipboard: ${text}`, 'success');
        });
      });
    });

    document.querySelectorAll('.faq-trigger').forEach((trigger) => {
      trigger.addEventListener('click', () => {
        const item = trigger.closest('.faq-item');
        if (item) item.classList.toggle('open');
      });
    });
  }

  // 9. Policy & Legal Modals (Section 38)
  const POLICY_CONTENT = {
    privacy: {
      title: 'Privacy Policy — Corelbase Private Limited',
      body: `
        <h4>1. Purpose &amp; Scope</h4>
        <p>Corelbase Private Limited (CIN: U62099UP2026PTC250888) collects personal, identity (KYC), and payment verification data solely for processing private investment applications, conducting statutory anti-fraud / AML checks, and maintaining corporate member registers under the Companies Act, 2013.</p>
        <h4>2. Data Collected</h4>
        <p>We collect your Full Name, Email, Mobile Number, Date of Birth, Residential Address, PAN Number, Aadhaar Number, UTR / Bank Reference Number, and Payment Confirmation Screenshot.</p>
        <h4>3. Cryptographic Protection &amp; Masking</h4>
        <p>PAN and Aadhaar numbers are encrypted at rest using AES-256-GCM and stored alongside masked representations (<code>ABCDE****F</code>, <code>********1234</code>). Full KYC identifiers, bank screenshots, and personal contact details are strictly never displayed on public tracking or certificate verification pages.</p>
        <h4>4. Contact for Privacy Queries</h4>
        <p>Email: <strong>support@corelbase.com</strong> | Registered Office: HOUSE NO 173, BHARROH, GORAKHPUR, UTTAR PRADESH - 273408.</p>
      `,
    },
    terms: {
      title: 'Terms & Conditions — Corelbase Investor Portal',
      body: `
        <h4>1. Nature of Portal</h4>
        <p>This website is an Online Investment Application &amp; Investor Management Portal operated by Corelbase Private Limited. It is NOT a stock exchange, public securities marketplace, or automated share-trading platform.</p>
        <h4>2. No Automatic Share Allotment</h4>
        <p>Submitting an online application or transferring funds to Corelbase Private Limited's bank account does not automatically create shareholder status or guarantee share allotment. All applications are subject to payment verification, duplicate/fraud screening, board approval, and execution of statutory documentation.</p>
        <h4>3. Accuracy of Information</h4>
        <p>Applicants must remit funds solely from a bank account matching their verified KYC identity and provide an authentic UTR reference number.</p>
      `,
    },
    disclaimer: {
      title: 'Statutory Investment Disclaimer',
      body: `
        <h4>Important Notice to Applicants</h4>
        <p>Corelbase Private Limited is a private limited company. This website is an investment application and investor information portal and is not a stock exchange, securities trading platform, or open public securities marketplace. Submission of an application or payment does not by itself constitute allotment or confer shareholder status.</p>
        <h4>No Guaranteed Returns</h4>
        <p>Investments in early-stage technology and infrastructure private limited companies carry inherent business and illiquidity risks. Corelbase Private Limited makes no representation or claim of guaranteed returns, risk-free profits, or public market liquidity. Prospective applicants should consult independent legal, tax, and financial advisors.</p>
      `,
    },
    refund: {
      title: 'Refund & Application Cancellation Policy',
      body: `
        <h4>1. Unallotted or Rejected Applications</h4>
        <p>If an investment application is rejected or cancelled prior to formal share allotment (for example, due to failed KYC verification, unverified payment, or board non-approval), any verified remittance received in Corelbase Private Limited's bank account will be refunded to the remitter's source bank account in accordance with applicable Indian corporate laws.</p>
        <h4>2. Post-Allotment</h4>
        <p>Once shares are formally allotted by the Board of Directors and share certificates are issued, the shareholding is governed by the Articles of Association of Corelbase Private Limited and the Companies Act, 2013.</p>
      `,
    },
    kyc: {
      title: 'KYC & Data Protection Notice',
      body: `
        <h4>Why KYC is Mandatory</h4>
        <p>Under Indian corporate and anti-money-laundering norms, Corelbase Private Limited must verify the identity (PAN and Aadhaar) and residential address of every prospective investor before considering any share allotment.</p>
        <h4>Storage of Payment Documents</h4>
        <p>Uploaded payment screenshots are validated for file type and MIME integrity, stored in non-executable server directories indexed by Investor ID and UTR, and accessed exclusively by authorized administrators with full audit logging.</p>
      `,
    },
  };

  window.openPolicyModal = function (key) {
    const doc = POLICY_CONTENT[key];
    if (!doc) return;
    const backdrop = document.getElementById('policyModalBackdrop');
    const titleEl = document.getElementById('policyModalTitle');
    const bodyEl = document.getElementById('policyModalBody');
    if (titleEl) titleEl.textContent = doc.title;
    if (bodyEl) bodyEl.innerHTML = doc.body;
    if (backdrop) backdrop.classList.add('open');
  };

  window.closePolicyModal = function () {
    const backdrop = document.getElementById('policyModalBackdrop');
    if (backdrop) backdrop.classList.remove('open');
  };

  document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initMobileNav();
    initCalculator();
    initWizardListeners();
    initFormSubmission();
    initCopyAndFaq();
    loadPublicConfig();
  });
})();
