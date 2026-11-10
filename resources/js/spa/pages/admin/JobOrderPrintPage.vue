<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AppLink from '../../components/AppLink.vue';
import { ensureRoles } from '../../composables/auth';
import { navigate } from '../../navigation';

const props = defineProps({
    params: {
        type: Object,
        default: () => ({}),
    },
});

const loading = ref(true);
const forbidden = ref(false);
const errorMessage = ref('');
const printData = ref(null);
const claimQr = ref(null);
const tagQr = ref(null);
const claimStub = ref(null);
const claimInfoGrid = ref(null);
const claimTermsSection = ref(null);
const claimTermsList = ref(null);
const claimFitScale = ref(1);
const claimFitReady = ref(false);
const claimContentFits = ref(true);
const minimumClaimTermsFontSize = 4.6;
const baseClaimTermsFontSize = 5.15;
const minimumClaimFitScale = minimumClaimTermsFontSize / baseClaimTermsFontSize;

const isWarrantyMode = computed(() => props.params.mode === 'warranty');
const title = computed(() => (isWarrantyMode.value ? 'Warranty Slip' : 'Claim Stub and Job Order Slip'));

const formatCurrency = (value) => {
    const amount = Number(value || 0);
    return `PHP ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const formatStatus = (status) => {
    if (status === 'PICKED_UP') return 'RELEASED';
    if (status === 'RTO_RELEASED') return 'RTO RELEASED';
    if (status === 'RTO') return 'RTO';
    return status ? status.replaceAll('_', ' ') : '';
};

const renderQr = async () => {
    await nextTick();
    if (!printData.value?.tracking?.qr_value || !window.QRCode) return;

    [
        { node: claimQr.value, size: 62 },
        { node: tagQr.value, size: 68 },
    ].forEach(({ node, size }) => {
        if (!node) return;
        node.innerHTML = '';
        new window.QRCode(node, {
            text: printData.value.tracking.qr_value,
            width: size,
            height: size,
            colorDark: '#000000',
            colorLight: '#ffffff',
            correctLevel: window.QRCode.CorrectLevel.M,
        });
    });
};

const doesClaimContentFit = async () => {
    await nextTick();
    const infoFits = !claimInfoGrid.value
        || claimInfoGrid.value.scrollHeight <= claimInfoGrid.value.clientHeight + 1;
    const termsFit = !claimTermsSection.value
        || !claimTermsList.value
        || claimTermsSection.value.scrollHeight <= claimTermsSection.value.clientHeight + 1;
    const stubFits = !claimStub.value
        || claimStub.value.scrollHeight <= claimStub.value.clientHeight + 1;

    return infoFits && termsFit && stubFits;
};

const fitClaimContent = async () => {
    claimFitReady.value = false;
    claimFitScale.value = 1;

    if (await doesClaimContentFit()) {
        claimContentFits.value = true;
        claimFitReady.value = true;
        return;
    }

    claimFitScale.value = minimumClaimFitScale;
    if (!(await doesClaimContentFit())) {
        claimContentFits.value = false;
        claimFitReady.value = true;
        return;
    }

    let low = minimumClaimFitScale;
    let high = 1;
    for (let attempt = 0; attempt < 8; attempt += 1) {
        const scale = (low + high) / 2;
        claimFitScale.value = scale;

        if (await doesClaimContentFit()) {
            low = scale;
        } else {
            high = scale;
        }
    }

    claimFitScale.value = low;
    claimContentFits.value = true;
    claimFitReady.value = true;
};

const loadPrintData = async () => {
    const auth = await ensureRoles(['admin', 'staff']);
    if (!auth.ok) {
        if (auth.reason === 'unauthenticated') {
            navigate('/login', { replace: true });
            return;
        }
        forbidden.value = true;
        loading.value = false;
        return;
    }

    try {
        const query = isWarrantyMode.value ? '?warranty=1' : '';
        const { data } = await window.axios.get(`/api/admin/job-orders/${props.params.id}/print-data${query}`);
        printData.value = data;
    } catch (error) {
        forbidden.value = true;
        errorMessage.value = error?.response?.data?.message || 'Unable to load print document.';
    } finally {
        loading.value = false;
    }

    if (printData.value && !isWarrantyMode.value) {
        await renderQr();
        await fitClaimContent();
    }
};

const printPage = () => window.print();

onMounted(loadPrintData);
</script>

<template>
  <AdminLayout :title="title">
    <div v-if="loading" class="card p-6 text-slate-600">Loading print document...</div>
    <div v-else-if="forbidden" class="card border border-rose-200 bg-rose-50 p-6 text-rose-700">{{ errorMessage || 'You do not have access to this page.' }}</div>

    <div v-else-if="printData" class="space-y-4">
      <div class="no-print flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-3">
          <AppLink :to="`/admin/job-orders/${props.params.id}`" class="rounded-lg bg-slate-100 px-4 py-2 font-semibold text-slate-700 hover:bg-slate-200">Back to Job Order</AppLink>
          <AppLink v-if="!isWarrantyMode && printData.warranty?.is_available" :to="`/admin/job-orders/${props.params.id}/print/warranty`" class="rounded-lg bg-slate-100 px-4 py-2 font-semibold text-slate-700 hover:bg-slate-200">Warranty Slip</AppLink>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-3">
          <p v-if="!isWarrantyMode && claimFitReady && !claimContentFits" class="claim-fit-warning" role="alert">
            This content exceeds the readable capacity of the fixed 8 × 10 cm Claim Stub. Printing is disabled to prevent omitted information. Keep configured Terms & Conditions to about 850 characters or less and shorten unusually long customer/device details; actual capacity depends on wrapping.
          </p>
          <button
            type="button"
            class="rounded-lg bg-slate-950 px-5 py-2 font-bold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="!isWarrantyMode && (!claimFitReady || !claimContentFits)"
            @click="printPage"
          >Print</button>
        </div>
      </div>

      <div v-if="isWarrantyMode" class="print-area">
        <section class="warranty-slip slip">
          <header class="document-header">
            <img v-if="printData.shop.logo_url" :src="printData.shop.logo_url" alt="Shop logo" class="print-logo" />
            <h1>{{ printData.shop.name }}</h1>
            <p>{{ printData.shop.tagline }}</p>
            <p>{{ printData.shop.address || '' }}</p>
          </header>

          <div class="title-row warranty-title">
            <div>
              <p class="kicker">Service Warranty Certificate</p>
              <h2>Warranty Slip</h2>
            </div>
            <div class="reference">
              <strong>{{ printData.jobOrder.job_order_number }}</strong>
              <span>Issued {{ printData.generated_at }}</span>
            </div>
          </div>

          <div v-if="!printData.warranty.is_available" class="warning">
            No warranty is set. Add warranty details before issuing this slip.
          </div>

          <div class="warranty-validity">
            <div>
              <span>Warranty Period</span>
              <strong>{{ printData.warranty.days }} day{{ printData.warranty.days === 1 ? '' : 's' }}</strong>
            </div>
            <div>
              <span>Starts On</span>
              <strong>{{ printData.warranty.starts_at_formatted || 'Pending release' }}</strong>
            </div>
            <div>
              <span>Valid Until</span>
              <strong>{{ printData.warranty.expires_at_formatted || 'Pending release' }}</strong>
            </div>
          </div>

          <div class="info-grid warranty-info">
            <p><span>Customer</span><strong>{{ printData.customer.name }}</strong></p>
            <p><span>Device / Model</span><strong>{{ printData.device.label || printData.device.type }}</strong></p>
            <p><span>Serial Number</span><strong>{{ printData.device.serial_number || 'N/A' }}</strong></p>
            <p><span>Service / Repair</span><strong>{{ printData.service.service_performed || 'Not specified' }}</strong></p>
          </div>

          <div class="coverage-box">
            <span>Warranty Coverage</span>
            <strong>{{ printData.warranty.details || 'Coverage applies only to the repair performed on this job order.' }}</strong>
          </div>

          <div class="terms-section warranty-terms-section">
            <h3>Terms & Conditions</h3>
            <ol class="terms-list">
              <li v-for="term in printData.warranty_terms" :key="term">{{ term }}</li>
            </ol>
          </div>

          <div class="signature-grid">
            <span>Issued by</span>
            <span>Customer signature over printed name</span>
          </div>

          <footer class="warranty-footer">
            <strong>{{ printData.shop.name }}</strong>
            <span>{{ printData.shop.phone || 'Contact number not set' }}</span>
          </footer>
        </section>
      </div>

      <div v-else class="print-area intake-slips">
        <section ref="claimStub" class="claim-stab slip" :style="{ '--claim-fit-scale': claimFitScale }">
          <header class="document-header">
            <img v-if="printData.shop.logo_url" :src="printData.shop.logo_url" alt="Shop logo" class="print-logo" />
            <h1>{{ printData.shop.name }}</h1>
            <p>{{ printData.shop.tagline }}</p>
            <p>{{ printData.shop.address || '' }}</p>
          </header>

          <div class="title-row">
            <h2>Claim Stub</h2>
            <span>{{ printData.service.received_at_formatted }}</span>
          </div>

          <div class="claim-reference">
            <strong>{{ printData.jobOrder.job_order_number }}</strong>
            <span>{{ formatStatus(printData.service.current_status) }}</span>
          </div>

          <div ref="claimInfoGrid" class="info-grid claim-info">
            <p><span>Customer</span><strong>{{ printData.customer.name }}</strong></p>
            <p><span>Device</span><strong>{{ printData.device.label || printData.device.type }}</strong></p>
            <p><span>Serial Number</span><strong>{{ printData.device.serial_number || 'N/A' }}</strong></p>
            <p class="full-row"><span>Reported Problem</span><strong>{{ printData.device.problem_description }}</strong></p>
            <p class="full-row"><span>Accessories Received</span><strong>{{ printData.device.included_accessories || 'None recorded' }}</strong></p>
            <p><span>Date Received</span><strong>{{ printData.service.received_at_formatted }}</strong></p>
            <p><span>Estimated Cost</span><strong>{{ formatCurrency(printData.costs.estimated_cost) }}</strong></p>
            <p><span>Current Repair Total</span><strong>{{ formatCurrency(printData.costs.total_cost) }}</strong></p>
            <p><span>Amount Paid</span><strong>{{ formatCurrency(printData.costs.amount_paid) }}</strong></p>
            <p><span>Outstanding Balance</span><strong>{{ formatCurrency(printData.costs.balance) }}</strong></p>
            <p><span>Payment Status</span><strong>{{ printData.costs.payment_status || 'PENDING' }}</strong></p>
            <p><span>Target Date</span><strong>{{ printData.service.estimated_completion_formatted || 'To be advised' }}</strong></p>
          </div>

          <div ref="claimTermsSection" class="terms-section claim-terms-section">
            <h3>Terms & Conditions</h3>
            <ol ref="claimTermsList" class="terms-list claim-terms">
              <li v-for="term in printData.claim_terms" :key="term">{{ term }}</li>
            </ol>
          </div>

          <div class="claim-bottom">
            <div class="claim-release">
              <div class="claim-signature">Signature over printed name</div>
              <p>Present this claim stub when claiming the device.</p>
              <strong>{{ printData.shop.phone || 'Contact number not set' }}</strong>
            </div>
            <div class="qr-block">
              <div ref="claimQr" class="claim-qr"></div>
              <p>Scan to track repair</p>
            </div>
          </div>
        </section>

        <section class="job-order-slip slip">
          <header class="document-header">
            <img v-if="printData.shop.logo_url" :src="printData.shop.logo_url" alt="Shop logo" class="print-logo" />
            <h1>{{ printData.shop.name }}</h1>
            <p>{{ printData.shop.tagline }}</p>
          </header>

          <div class="job-title">
            <div>
              <span>Job Order Slip</span>
              <h2>{{ printData.jobOrder.job_order_number }}</h2>
              <strong>{{ formatStatus(printData.service.current_status) }}</strong>
            </div>
            <div class="qr-block">
              <div ref="tagQr" class="tag-qr"></div>
              <p>Scan to track</p>
            </div>
          </div>

          <div class="tag-section">
            <h3>Device Identification</h3>
            <div class="info-grid tag-fields">
              <p><span>Device</span><strong>{{ printData.device.label || printData.device.type }}</strong></p>
              <p><span>Serial</span><strong>{{ printData.device.serial_number || 'N/A' }}</strong></p>
              <p class="full-row"><span>Problem</span><strong>{{ printData.device.problem_description }}</strong></p>
              <p class="full-row"><span>Accessories</span><strong>{{ printData.device.included_accessories || 'None recorded' }}</strong></p>
            </div>
          </div>

          <div class="tag-section">
            <h3>Service Assignment</h3>
            <div class="info-grid tag-fields">
              <p><span>Technician</span><strong>{{ printData.service.technician || 'Unassigned' }}</strong></p>
              <p><span>Status</span><strong>{{ formatStatus(printData.service.current_status) }}</strong></p>
            </div>
          </div>

          <footer class="tag-footer">Attach securely to the customer device.</footer>
        </section>
      </div>

    </div>
  </AdminLayout>
</template>

<style scoped>
.print-area {
  display: flex;
  align-items: flex-start;
  gap: 0.6cm;
  overflow-x: auto;
  padding: 0.4cm;
  background: #f1f5f9;
}

.slip {
  flex: 0 0 auto;
  box-sizing: border-box;
  overflow: hidden;
  border: 1.5px solid #000;
  background: #fff;
  color: #000;
  font-family: Arial, sans-serif;
}

.claim-stab,
.job-order-slip {
  width: 8cm;
  height: 10cm;
  padding: 0.22cm;
}

.claim-stab,
.job-order-slip,
.warranty-slip {
  display: flex;
  flex-direction: column;
}

.warranty-slip {
  width: 10cm;
  height: 13cm;
  padding: 0.3cm;
}

.document-header {
  border-bottom: 1.5px solid #000;
  padding-bottom: 0.08cm;
  text-align: center;
}

.print-logo {
  display: block;
  width: 0.62cm;
  height: 0.62cm;
  object-fit: contain;
  margin: 0 auto 0.04cm;
}

.document-header h1 {
  margin: 0;
  font-size: 12px;
  font-weight: 900;
  line-height: 1.05;
  text-transform: uppercase;
}

.document-header p {
  margin: 1px 0 0;
  font-size: 5.4px;
  font-weight: 700;
  line-height: 1.15;
}

.title-row {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 4px;
  margin: 0.1cm 0;
}

.title-row h2 {
  margin: 0;
  font-size: 11px;
  font-style: italic;
  font-weight: 900;
  line-height: 1;
}

.title-row > span,
.reference span {
  font-size: 5.3px;
}

.claim-reference {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border: 1px solid #000;
  padding: 0.06cm 0.08cm;
  font-size: 6px;
}

.claim-reference strong {
  font-size: 7.2px;
}

.claim-reference span,
.job-title > div:first-child > strong {
  border: 1px solid #000;
  padding: 1px 3px;
  font-size: 5px;
  font-weight: 900;
}

.info-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  border-left: 1px solid #000;
  border-top: 1px solid #000;
}

.info-grid p {
  min-width: 0;
  margin: 0;
  border-right: 1px solid #000;
  border-bottom: 1px solid #000;
  padding: 0.045cm 0.06cm;
  overflow-wrap: anywhere;
}

.info-grid span,
.coverage-box span,
.warranty-validity span {
  display: block;
  margin-bottom: 1px;
  font-size: 4.6px;
  font-weight: 700;
  text-transform: uppercase;
}

.info-grid strong {
  display: block;
  font-size: 5.8px;
  line-height: 1.15;
}

.full-row {
  grid-column: 1 / -1;
}

.claim-info {
  flex: 1 1 auto;
  min-height: 0;
  margin-top: 0.08cm;
  overflow: hidden;
}

.terms-section {
  min-height: 0;
}

.terms-section h3,
.tag-section h3 {
  margin: 0.09cm 0 0.04cm;
  border-bottom: 1px solid #000;
  padding-bottom: 0.03cm;
  text-align: center;
  font-size: 6.4px;
  font-weight: 900;
  text-transform: uppercase;
}

.claim-terms-section,
.warranty-terms-section {
  flex: 1;
}

.claim-terms-section {
  flex: 1 1 auto;
  overflow: hidden;
}

.terms-list {
  margin: 0;
  padding-left: 0.34cm;
  font-size: 5.15px;
  line-height: 1.18;
}

.terms-list li {
  margin-bottom: 1.5px;
  padding-left: 1px;
}

.claim-bottom {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 62px;
  gap: 0.12cm;
  align-items: end;
  flex: 0 0 auto;
  margin-top: 0.08cm;
}

.claim-release {
  font-size: 5.2px;
  font-weight: 700;
  overflow-wrap: anywhere;
}

.claim-release p {
  margin: 0.05cm 0;
}

.claim-signature {
  border-top: 1px solid #000;
  padding-top: 2px;
}

.qr-block {
  text-align: center;
}

.qr-block p {
  margin: 1px 0 0;
  font-size: 4.5px;
  font-weight: 900;
}

.claim-qr {
  width: 62px;
  height: 62px;
}

.job-title {
  display: grid;
  grid-template-columns: 1fr 68px;
  gap: 0.12cm;
  align-items: center;
  border-bottom: 1.5px solid #000;
  padding: 0.1cm 0;
}

.job-title span {
  display: block;
  font-size: 6px;
  font-weight: 800;
  text-transform: uppercase;
}

.job-title h2 {
  margin: 0.05cm 0 0.08cm;
  overflow-wrap: anywhere;
  font-size: 11px;
  font-weight: 900;
  line-height: 1.05;
}

.tag-qr {
  width: 68px;
  height: 68px;
}

.tag-section {
  margin-top: 0.08cm;
}

.tag-fields strong {
  font-size: 5.7px;
}

.tag-fields .full-row strong {
  max-height: 0.46cm;
  overflow: hidden;
}

.tag-footer {
  margin-top: 0.06cm;
  text-align: center;
  font-size: 4.8px;
  font-weight: 700;
}

.warranty-title {
  align-items: center;
  margin: 0.14cm 0 0.1cm;
}

.kicker {
  margin: 0 0 2px;
  font-size: 5px;
  font-weight: 800;
  text-transform: uppercase;
}

.reference {
  text-align: right;
}

.reference strong,
.reference span {
  display: block;
}

.reference strong {
  font-size: 7px;
}

.warning {
  margin-bottom: 0.08cm;
  border: 1px solid #000;
  padding: 0.06cm;
  font-size: 5.8px;
  font-weight: 800;
  text-align: center;
}

.warranty-validity {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  border: 1.5px solid #000;
}

.warranty-validity div {
  padding: 0.08cm;
  text-align: center;
}

.warranty-validity div + div {
  border-left: 1px solid #000;
}

.warranty-validity strong {
  display: block;
  font-size: 6.5px;
}

.warranty-info {
  margin-top: 0.12cm;
}

.warranty-info strong {
  font-size: 6.4px;
}

.coverage-box {
  margin-top: 0.12cm;
  border: 1.5px solid #000;
  padding: 0.08cm;
}

.coverage-box strong {
  display: block;
  max-height: 0.62cm;
  overflow: hidden;
  font-size: 6.2px;
  line-height: 1.2;
}

.warranty-slip .terms-list {
  font-size: 6.1px;
  line-height: 1.25;
}

.signature-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.65cm;
  margin-top: 0.18cm;
  font-size: 5.6px;
  font-weight: 700;
}

.signature-grid span {
  border-top: 1px solid #000;
  padding-top: 2px;
  text-align: center;
}

.warranty-footer {
  display: flex;
  justify-content: space-between;
  margin-top: 0.15cm;
  border-top: 1px solid #000;
  padding-top: 0.05cm;
  font-size: 5px;
}

.claim-fit-warning {
  max-width: 44rem;
  color: #b91c1c;
  font-size: 0.75rem;
  font-weight: 700;
}

.claim-stab .document-header {
  padding-bottom: calc(0.08cm * var(--claim-fit-scale));
}

.claim-stab .document-header h1 {
  font-size: calc(12px * var(--claim-fit-scale));
}

.claim-stab .document-header p {
  margin-top: calc(1px * var(--claim-fit-scale));
  font-size: calc(5.4px * var(--claim-fit-scale));
}

.claim-stab > .title-row {
  gap: calc(4px * var(--claim-fit-scale));
  margin: calc(0.1cm * var(--claim-fit-scale)) 0;
}

.claim-stab > .title-row h2 {
  font-size: calc(11px * var(--claim-fit-scale));
}

.claim-stab > .title-row > span {
  font-size: calc(5.3px * var(--claim-fit-scale));
}

.claim-stab .claim-reference {
  padding: calc(0.06cm * var(--claim-fit-scale)) calc(0.08cm * var(--claim-fit-scale));
  font-size: calc(6px * var(--claim-fit-scale));
}

.claim-stab .claim-reference strong {
  font-size: calc(7.2px * var(--claim-fit-scale));
}

.claim-stab .claim-reference span {
  padding: calc(1px * var(--claim-fit-scale)) calc(3px * var(--claim-fit-scale));
  font-size: calc(5px * var(--claim-fit-scale));
}

.claim-stab .info-grid p {
  padding: calc(0.045cm * var(--claim-fit-scale)) calc(0.06cm * var(--claim-fit-scale));
}

.claim-stab .info-grid span {
  margin-bottom: calc(1px * var(--claim-fit-scale));
  font-size: calc(4.6px * var(--claim-fit-scale));
}

.claim-stab .info-grid strong {
  font-size: calc(5.8px * var(--claim-fit-scale));
  line-height: 1.15;
}

.claim-stab .claim-info {
  margin-top: calc(0.08cm * var(--claim-fit-scale));
}

.claim-stab .claim-terms-section h3 {
  margin: calc(0.09cm * var(--claim-fit-scale)) 0 calc(0.04cm * var(--claim-fit-scale));
  padding-bottom: calc(0.03cm * var(--claim-fit-scale));
  font-size: calc(6.4px * var(--claim-fit-scale));
}

.claim-stab .claim-terms {
  padding-left: calc(0.34cm * var(--claim-fit-scale));
  font-size: calc(5.15px * var(--claim-fit-scale));
  line-height: 1.18;
}

.claim-stab .claim-terms li {
  margin-bottom: calc(1.5px * var(--claim-fit-scale));
  padding-left: calc(1px * var(--claim-fit-scale));
}

@media print {
  @page {
    margin: 5mm;
  }

  .no-print {
    display: none !important;
  }

  :deep(.content-header),
  :deep(.sidebar) {
    display: none !important;
  }

  :deep(.app-shell),
  :deep(.p-6),
  :deep(.space-y-4) {
    margin: 0 !important;
    padding: 0 !important;
    background: #fff !important;
  }

  .print-area {
    gap: 0.4cm;
    overflow: visible;
    padding: 0;
    background: #fff;
  }

  .slip {
    break-inside: avoid;
    box-shadow: none;
    print-color-adjust: exact;
  }

}
</style>
