<h2 class="h5 mt-4">RadiumBox Storefront</h2>
<p class="text-muted small mb-3">Controls storefront exposure and configurable service eligibility for the mapped RadiumBox model. Pricing remains driven by unit price sync.</p>
<div class="row g-3">
    <div class="col-md-4">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="sell_on_radiumbox" value="1" id="sell_on_radiumbox" @checked(old('sell_on_radiumbox', $product?->sell_on_radiumbox ?? true))>
            <label class="form-check-label" for="sell_on_radiumbox">Sell on RadiumBox</label>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="rd_service_available" value="1" id="rd_service_available" @checked(old('rd_service_available', $product?->rd_service_available ?? true))>
            <label class="form-check-label" for="rd_service_available">RD Service available</label>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="amc_available" value="1" id="amc_available" @checked(old('amc_available', $product?->amc_available ?? true))>
            <label class="form-check-label" for="amc_available">AMC available</label>
        </div>
    </div>
</div>
