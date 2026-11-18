<div class="pb-grid-3">
    <div class="f-field"><label>Date &amp; time sampled</label><input class="pb-input" type="datetime-local" name="data[sampled_at]" value="{{ old('data.sampled_at') }}" required></div>
    <div class="f-field"><label>Lab name</label><input class="pb-input" name="data[lab_name]" value="{{ old('data.lab_name') }}" required></div>
    <div class="f-field"><label>Lab sampler's name</label><input class="pb-input" name="data[sampler_name]" value="{{ old('data.sampler_name') }}" required></div>
    <div class="f-field"><label>Video ID of the sampling</label><input class="pb-input" name="data[video_id]" value="{{ old('data.video_id') }}" required></div>
    <div class="f-field"><label>Retained sample quantity</label><input class="pb-input" name="data[retained_qty]" value="{{ old('data.retained_qty') }}" required></div>
    <div class="f-field"><label>Where retained samples are kept</label><input class="pb-input" name="data[retained_location]" value="{{ old('data.retained_location') }}" required></div>
</div>
