<section class="customer-360-section" data-customer-360-section="summary" aria-labelledby="customer-360-summary-heading">
    <h3 class="customer-360-section-title" id="customer-360-summary-heading">Customer Summary</h3>
    <div class="customer-360-summary-grid">
        <div class="customer-360-summary-item">
            <span class="customer-360-summary-value">{{ $summary['total_orders'] ?? 0 }}</span>
            <span class="customer-360-summary-label">Total Orders</span>
        </div>
        <div class="customer-360-summary-item">
            <span class="customer-360-summary-value">{{ $summary['total_devices'] ?? 0 }}</span>
            <span class="customer-360-summary-label">Total Devices</span>
        </div>
        <div class="customer-360-summary-item">
            <span class="customer-360-summary-value">{{ $summary['open_cases'] ?? 0 }}</span>
            <span class="customer-360-summary-label">Open Cases</span>
        </div>
        <div class="customer-360-summary-item">
            <span class="customer-360-summary-value">{{ $summary['closed_cases'] ?? 0 }}</span>
            <span class="customer-360-summary-label">Closed Cases</span>
        </div>
    </div>
</section>
