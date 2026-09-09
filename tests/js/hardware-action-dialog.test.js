import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../resources/js/workspace/http', () => ({
    csrfToken: () => 'test-csrf',
    workspaceFetchHeaders: () => ({ Accept: 'application/json' }),
    workspaceFetch: vi.fn(),
}));

import { workspaceFetch } from '../../resources/js/workspace/http';
import {
    bindHardwareActionForms,
    initHardwareSerialSummaries,
    SERIAL_ALLOCATE_MESSAGES,
} from '../../resources/js/hardware-action-dialog';

const lineHtml = ({
    itemId,
    qty,
    product = 'MFS110',
    sku = 'RBMFS110L1',
} = {}) => `
    <div data-hardware-serial-picker
         data-item-id="${itemId}"
         data-qty="${qty}"
         data-search-url="/inventory/hardware-fulfilments/1/serials/search"
         data-product="${product}"
         data-sku="${sku}">
        <p data-hardware-serial-count>Serials allocated: 0 / ${qty}</p>
        <input data-hardware-serial-query>
        <div data-hardware-serial-results></div>
        <ul data-hardware-serial-selected></ul>
        <p class="d-none" data-hardware-serial-line-error></p>
    </div>
`;

const mountAllocateForm = ({ lines, submitLabel = 'Allocate Serials' }) => {
    document.body.innerHTML = `
        <div data-hardware-action-dialog-root>
            <div class="c360-dialog-body"></div>
            <form data-hardware-action-form id="hardware-action-serial-form" action="/allocate">
                <div data-hardware-serial-allocate data-required-total="${lines.reduce((sum, line) => sum + line.qty, 0)}">
                    ${lines.map((line) => lineHtml(line)).join('')}
                    <p data-hardware-serial-branch>Branch: Derived from selected serials</p>
                    <p data-hardware-serial-totals></p>
                    <p class="d-none" data-hardware-serial-client-error></p>
                </div>
                <button type="submit"
                        data-hardware-action-submit
                        data-hardware-serial-submit-idle="${submitLabel}"
                        disabled>${submitLabel}</button>
            </form>
        </div>
    `;

    return bindHardwareActionForms(document);
};

const searchAndRender = async (itemId, serials) => {
    workspaceFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ serials }),
    });
    const picker = document.querySelector(`[data-hardware-serial-picker][data-item-id="${itemId}"]`);
    const input = picker.querySelector('[data-hardware-serial-query]');
    input.value = 'SN';
    input.dispatchEvent(new Event('input'));
    await vi.advanceTimersByTimeAsync(200);
};

const clickResult = (itemId, serial) => {
    const picker = document.querySelector(`[data-hardware-serial-picker][data-item-id="${itemId}"]`);
    const button = Array.from(picker.querySelectorAll('[data-hardware-serial-results] button'))
        .find((el) => el.textContent === serial);
    button.click();
};

describe('hardware allocate serial popup', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.clearAllMocks();
        document.body.innerHTML = '';
        window.bootstrap = {
            Modal: {
                getOrCreateInstance: vi.fn(() => ({ show: vi.fn(), hide: vi.fn() })),
            },
        };
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('keeps qty 1 compact and disables submit until one serial is selected', async () => {
        mountAllocateForm({
            lines: [{ itemId: '11', qty: 1 }],
            submitLabel: 'Allocate Serial',
        });
        const submit = document.querySelector('[data-hardware-action-submit]');
        expect(submit.disabled).toBe(true);
        expect(submit.textContent.trim()).toBe('Allocate Serial');

        await searchAndRender('11', [{ serial_number: 'SN-1', branch_code: 'DELHI-RETAIL' }]);
        clickResult('11', 'SN-1');

        expect(submit.disabled).toBe(false);
        expect(document.querySelector('[data-hardware-serial-count]').textContent).toBe('Serials allocated: 1 / 1');
        expect(document.querySelector('input[name="serials[11][]"]').value).toBe('SN-1');
        expect(document.querySelector('[data-hardware-serial-branch]').textContent).toBe('Branch: Delhi');
    });

    it('requires exactly two serials for qty 2 and will not select a third', async () => {
        mountAllocateForm({ lines: [{ itemId: '21', qty: 2 }] });
        const submit = document.querySelector('[data-hardware-action-submit]');

        await searchAndRender('21', [
            { serial_number: 'SN-A', branch_code: 'DELHI-RETAIL' },
            { serial_number: 'SN-B', branch_code: 'DELHI-RETAIL' },
            { serial_number: 'SN-C', branch_code: 'DELHI-RETAIL' },
        ]);
        clickResult('21', 'SN-A');
        expect(submit.disabled).toBe(true);
        expect(document.querySelector('[data-hardware-serial-count]').textContent).toBe('Serials allocated: 1 / 2');

        clickResult('21', 'SN-B');
        expect(submit.disabled).toBe(false);
        expect(document.querySelectorAll('input[name="serials[21][]"]')).toHaveLength(2);

        clickResult('21', 'SN-C');
        expect(document.querySelectorAll('input[name="serials[21][]"]')).toHaveLength(2);
        expect(document.querySelector('[data-hardware-serial-line-error]').textContent)
            .toBe(SERIAL_ALLOCATE_MESSAGES.lineFull);
    });

    it('requires ten serials before enabling submit', async () => {
        mountAllocateForm({ lines: [{ itemId: '31', qty: 10 }] });
        const serials = Array.from({ length: 10 }, (_, index) => ({
            serial_number: `SN-${index + 1}`,
            branch_code: 'DELHI-RETAIL',
        }));
        await searchAndRender('31', serials);
        for (let index = 1; index <= 7; index += 1) {
            clickResult('31', `SN-${index}`);
        }
        expect(document.querySelector('[data-hardware-action-submit]').disabled).toBe(true);
        expect(document.querySelector('[data-hardware-serial-count]').textContent).toBe('Serials allocated: 7 / 10');

        for (let index = 8; index <= 10; index += 1) {
            clickResult('31', `SN-${index}`);
        }
        expect(document.querySelector('[data-hardware-action-submit]').disabled).toBe(false);
        expect(document.querySelectorAll('input[name="serials[31][]"]')).toHaveLength(10);
    });

    it('renders independent pickers for different product quantities', async () => {
        mountAllocateForm({
            lines: [
                { itemId: '41', qty: 2, product: 'MFS110', sku: 'RBMFS110L1' },
                { itemId: '42', qty: 1, product: 'MSO1300', sku: 'RBIMSOE3L1' },
            ],
        });
        expect(document.querySelectorAll('[data-hardware-serial-picker]')).toHaveLength(2);
        expect(document.querySelector('[data-item-id="41"]').dataset.qty).toBe('2');
        expect(document.querySelector('[data-item-id="42"]').dataset.qty).toBe('1');

        await searchAndRender('41', [
            { serial_number: 'SN-A1', branch_code: 'DELHI-RETAIL' },
            { serial_number: 'SN-A2', branch_code: 'DELHI-RETAIL' },
        ]);
        clickResult('41', 'SN-A1');
        clickResult('41', 'SN-A2');
        expect(document.querySelector('[data-hardware-action-submit]').disabled).toBe(true);

        await searchAndRender('42', [{ serial_number: 'SN-B1', branch_code: 'DELHI-RETAIL' }]);
        clickResult('42', 'SN-B1');
        expect(document.querySelector('[data-hardware-action-submit]').disabled).toBe(false);
        expect(document.querySelector('[data-hardware-serial-totals]').textContent)
            .toBe('Total required: 3 · Total selected: 3');
        expect(document.querySelector('input[name="serials[41][]"]').value).toBe('SN-A1');
        expect(document.querySelector('input[name="serials[42][]"]').value).toBe('SN-B1');
    });

    it('blocks a duplicate serial on the same line and across lines', async () => {
        mountAllocateForm({
            lines: [
                { itemId: '51', qty: 2 },
                { itemId: '52', qty: 1 },
            ],
        });
        await searchAndRender('51', [
            { serial_number: 'SN-X', branch_code: 'DELHI-RETAIL' },
            { serial_number: 'SN-Y', branch_code: 'DELHI-RETAIL' },
        ]);
        clickResult('51', 'SN-X');
        clickResult('51', 'SN-Y');
        expect(document.querySelectorAll('input[name="serials[51][]"]')).toHaveLength(2);

        await searchAndRender('52', [{ serial_number: 'SN-X', branch_code: 'DELHI-RETAIL' }]);
        clickResult('52', 'SN-X');
        expect(document.querySelector('[data-item-id="52"] [data-hardware-serial-line-error]').textContent)
            .toBe(SERIAL_ALLOCATE_MESSAGES.duplicate);
        expect(document.querySelectorAll('input[name="serials[52][]"]')).toHaveLength(0);
    });

    it('blocks mixed Delhi and Mumbai serials', async () => {
        mountAllocateForm({ lines: [{ itemId: '61', qty: 2 }] });
        await searchAndRender('61', [
            { serial_number: 'SN-D', branch_code: 'DELHI-RETAIL' },
            { serial_number: 'SN-M', branch_code: 'MUMBAI' },
        ]);
        clickResult('61', 'SN-D');
        clickResult('61', 'SN-M');
        expect(document.querySelector('[data-hardware-serial-client-error]').textContent)
            .toBe(SERIAL_ALLOCATE_MESSAGES.mixedBranch);
        expect(document.querySelectorAll('input[name="serials[61][]"]')).toHaveLength(1);
        expect(document.querySelector('[data-hardware-action-submit]').disabled).toBe(true);
    });

    it('allows remove and reselect', async () => {
        mountAllocateForm({ lines: [{ itemId: '71', qty: 1 }], submitLabel: 'Allocate Serial' });
        await searchAndRender('71', [{ serial_number: 'SN-R', branch_code: 'DELHI-RETAIL' }]);
        clickResult('71', 'SN-R');
        expect(document.querySelector('[data-hardware-action-submit]').disabled).toBe(false);

        document.querySelector('[data-hardware-serial-remove]').click();
        expect(document.querySelectorAll('input[name="serials[71][]"]')).toHaveLength(0);
        expect(document.querySelector('[data-hardware-action-submit]').disabled).toBe(true);

        clickResult('71', 'SN-R');
        expect(document.querySelector('[data-hardware-action-submit]').disabled).toBe(false);
    });

    it('prevents double submit and shows a backend rejection safely', async () => {
        mountAllocateForm({ lines: [{ itemId: '81', qty: 1 }], submitLabel: 'Allocate Serial' });
        await searchAndRender('81', [{ serial_number: 'SN-Z', branch_code: 'DELHI-RETAIL' }]);
        clickResult('81', 'SN-Z');
        workspaceFetch.mockClear();
        vi.useRealTimers();

        let resolvePost;
        workspaceFetch.mockImplementationOnce(() => new Promise((resolve) => {
            resolvePost = resolve;
        }));

        const form = document.querySelector('#hardware-action-serial-form');
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

        expect(workspaceFetch).toHaveBeenCalledTimes(1);
        expect(document.querySelector('[data-hardware-action-submit]').textContent).toBe('Allocating…');
        expect(document.querySelector('[data-hardware-action-submit]').disabled).toBe(true);

        resolvePost({
            ok: false,
            json: async () => ({ errors: { serials: ['Serial SN-Z belongs to the wrong product.'] } }),
        });

        await vi.waitFor(() => {
            expect(document.querySelector('[data-hardware-action-error]')?.textContent)
                .toBe('Serial SN-Z belongs to the wrong product.');
        });
        expect(document.querySelector('[data-hardware-action-submit]').textContent).toBe('Allocate Serial');
        expect(document.querySelector('[data-hardware-action-submit]').disabled).toBe(false);
    });
});

const mountInvoiceForm = () => {
    const showToast = vi.fn();
    document.body.innerHTML = `
        <div data-workspace-modal-host></div>
        <div data-hardware-action-dialog-root>
            <div class="c360-dialog-body"></div>
            <form data-hardware-action-form id="hardware-action-invoice-form" action="/invoice">
                <button type="submit" data-hardware-action-submit>Issue Invoice</button>
            </form>
        </div>
    `;
    bindHardwareActionForms(document);
    return showToast;
};

describe('hardware issue invoice popup', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        document.body.innerHTML = '';
        window.bootstrap = {
            Modal: {
                getOrCreateInstance: vi.fn(() => ({ show: vi.fn(), hide: vi.fn() })),
            },
        };
    });

    it('surfaces a JSON validation failure and keeps the modal open', async () => {
        mountInvoiceForm();
        workspaceFetch.mockResolvedValueOnce({
            ok: false,
            json: async () => ({
                message: 'GST rate is missing or invalid.',
                errors: { gst: ['GST rate is missing or invalid.'] },
            }),
        });

        document.querySelector('#hardware-action-invoice-form')
            .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

        await vi.waitFor(() => {
            expect(document.querySelector('[data-hardware-action-error]')?.textContent)
                .toBe('GST rate is missing or invalid.');
        });
        expect(window.bootstrap.Modal.getOrCreateInstance).not.toHaveBeenCalled();
        expect(document.querySelector('[data-hardware-action-submit]').disabled).toBe(false);
    });

    it('does not treat an HTML 200 redirect as a successful invoice', async () => {
        mountInvoiceForm();
        workspaceFetch.mockResolvedValueOnce({
            ok: true,
            json: async () => {
                throw new Error('Unexpected HTML');
            },
        });

        document.querySelector('#hardware-action-invoice-form')
            .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

        await vi.waitFor(() => {
            expect(document.querySelector('[data-hardware-action-error]')?.textContent)
                .toBe('This hardware action could not be completed.');
        });
        expect(window.bootstrap.Modal.getOrCreateInstance).not.toHaveBeenCalled();
    });

    it('closes the modal when the invoice JSON contract succeeds', async () => {
        mountInvoiceForm();
        workspaceFetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                ok: true,
                status: 'Hardware invoice INV-67299 issued.',
                next_action: 'Get Courier Options',
                mutating: true,
                action_dialog_url: null,
            }),
        });

        document.querySelector('#hardware-action-invoice-form')
            .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

        await vi.waitFor(() => {
            expect(window.bootstrap.Modal.getOrCreateInstance).toHaveBeenCalled();
        });
        expect(document.querySelector('[data-hardware-action-error]')).toBeNull();
    });

    it('ignores a repeated click while the invoice request is in flight', async () => {
        mountInvoiceForm();
        let resolvePost;
        workspaceFetch.mockImplementationOnce(() => new Promise((resolve) => {
            resolvePost = resolve;
        }));

        const form = document.querySelector('#hardware-action-invoice-form');
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

        expect(workspaceFetch).toHaveBeenCalledTimes(1);

        resolvePost({
            ok: true,
            json: async () => ({ ok: true, status: 'Hardware invoice INV-1 issued.' }),
        });

        await vi.waitFor(() => {
            expect(window.bootstrap.Modal.getOrCreateInstance).toHaveBeenCalled();
        });
    });
});

describe('hardware measured parcel form', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        document.body.innerHTML = `
            <form data-hardware-action-form
                  data-hardware-measured-parcel
                  action="/parcel-measured"
                  data-volumetric-divisor="5000"
                  data-max-dimension="200"
                  data-max-weight="99.999">
                <input type="number" data-hardware-measured-length>
                <input type="number" data-hardware-measured-breadth>
                <input type="number" data-hardware-measured-height>
                <input type="number" data-hardware-measured-weight>
                <span data-hardware-measured-actual-display>—</span>
                <span data-hardware-measured-volumetric-display>—</span>
                <button type="submit" data-hardware-action-submit data-hardware-measured-submit disabled>Save</button>
            </form>
        `;
        bindHardwareActionForms(document);
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('keeps submit disabled until complete packed shipment values are valid', () => {
        const submit = document.querySelector('[data-hardware-measured-submit]');
        expect(submit.hasAttribute('disabled')).toBe(true);

        document.querySelector('[data-hardware-measured-length]').value = '40';
        document.querySelector('[data-hardware-measured-breadth]').value = '30';
        document.querySelector('[data-hardware-measured-height]').value = '20';
        document.querySelector('[data-hardware-measured-weight]').value = '2.5';
        document.querySelector('[data-hardware-measured-length]').dispatchEvent(new Event('input'));

        expect(submit.hasAttribute('disabled')).toBe(false);
        expect(document.querySelector('[data-hardware-measured-actual-display]').textContent).toBe('2.50 kg');
        expect(document.querySelector('[data-hardware-measured-volumetric-display]').textContent).toBe('4.80 kg');
    });

    it('rejects unit-style dimensions at or below 0.50 cm', () => {
        document.querySelector('[data-hardware-measured-length]').value = '0.5';
        document.querySelector('[data-hardware-measured-breadth]').value = '9';
        document.querySelector('[data-hardware-measured-height]').value = '7';
        document.querySelector('[data-hardware-measured-weight]').value = '0.24';
        document.querySelector('[data-hardware-measured-length]').dispatchEvent(new Event('input'));

        expect(document.querySelector('[data-hardware-measured-submit]').hasAttribute('disabled')).toBe(true);
        expect(document.querySelector('[data-hardware-measured-volumetric-display]').textContent).toBe('—');
    });

    it('does not post until the packed shipment is valid', () => {
        const form = document.querySelector('[data-hardware-measured-parcel]');
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        expect(workspaceFetch).not.toHaveBeenCalled();
    });
});

describe('hardware allocated serial summary', () => {
    beforeAll(() => {
        initHardwareSerialSummaries();
    });

    beforeEach(() => {
        document.body.innerHTML = `
            <div data-hardware-serial-summary data-hardware-serial-id="serial-summary-test" class="hardware-serial-summary">
                <button type="button" data-hardware-serial-toggle aria-expanded="false">10532347 +9</button>
                <div data-hardware-serial-panel hidden>
                    <p>Allocated Serials (10)</p>
                    <ol>
                        <li>10532347</li>
                        <li>10556040</li>
                    </ol>
                    <button type="button" data-copyable-identifier data-copy-value="10532347\n10556040">Copy All</button>
                </div>
            </div>
        `;
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('opens the complete list on click and keeps serials readable', () => {
        const toggle = document.querySelector('[data-hardware-serial-toggle]');
        toggle.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

        const panel = document.querySelector('[data-hardware-serial-panel]');
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        expect(panel.hidden).toBe(false);
        expect(panel.parentElement).toBe(document.body);
        expect(panel.textContent).toContain('10532347');
        expect(panel.textContent).toContain('10556040');
        expect(panel.querySelector('[data-copyable-identifier]').dataset.copyValue).toBe('10532347\n10556040');
    });

    it('closes on Escape', () => {
        const toggle = document.querySelector('[data-hardware-serial-toggle]');
        toggle.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(document.querySelector('[data-hardware-serial-panel]').hidden).toBe(true);
    });
});
