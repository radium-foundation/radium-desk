import { describe, expect, it } from 'vitest';
import {
    buildLegacyPreviewSections,
    buildLegacyPreviewSummaryHtml,
} from '../../resources/js/intake-search-flow';

describe('legacy preview sections', () => {
    const preview = {
        order_id: 'RDE177816',
        legacy_order_date: '16 Jan 2026, 09:27 PM',
        legacy_order_status: 'Shipped',
        purchase_year: '2026',
        customer_name: 'Pallab Mukherjee',
        mobile: '9874773752',
        email: 'ejobfacts2@gmail.com',
        delivery_address_display: 'Sukanta Sarani R.K.Pally, Sonarpur\nSouth 24 Parganas\nWest Bengal\nPIN 700150 (order checkout; customer profile PIN differs)',
        product_model: 'Mantra MFS 100 / 110 L1 Fingerprint Scanner',
        serial_number: '10024774',
        product_variant: 'MFS 110',
        product_sku: 'PMTMFS110Z',
        payment_status: 'Paid',
        payment_method: 'cashfree',
        payment_amount_display: '₹2735',
        invoice_number: 'IND568704',
        invoice_date: '17 Jan 2026, 10:20 AM',
        shipment_status: 'Shipped',
        awb: '19041860689453',
    };

    it('builds grouped preview sections for rde hardware orders', () => {
        const sections = buildLegacyPreviewSections(preview);

        expect(sections.map((section) => section.title)).toEqual([
            'Order',
            'Customer',
            'Delivery Address',
            'Product / Hardware',
            'Payment',
            'Invoice',
            'Shipment',
        ]);

        expect(sections.find((section) => section.title === 'Payment')?.fields).toEqual([
            ['Payment status', 'Paid'],
            ['Payment method', 'cashfree'],
            ['Amount paid', '₹2735'],
        ]);
    });

    it('renders grouped html for dashboard legacy preview card', () => {
        const html = buildLegacyPreviewSummaryHtml(preview);

        expect(html).toContain('Delivery Address');
        expect(html).toContain('PIN 700150 (order checkout; customer profile PIN differs)');
        expect(html).toContain('AWB / tracking');
        expect(html).toContain('19041860689453');
        expect(html).not.toContain('payment_reference');
    });
});
