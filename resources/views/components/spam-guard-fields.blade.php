{{-- honeypot: 見えない position で bot だけが埋める --}}
<div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;height:0;width:0;overflow:hidden">
    <label>Website
        <input type="text" name="website" tabindex="-1" autocomplete="off" value="">
    </label>
</div>
{{-- time trap: フォーム描画時刻を暗号化 (APP_KEY で署名)。2 秒未満で submit されると reject --}}
<input type="hidden" name="form_ts" value="{{ \Illuminate\Support\Facades\Crypt::encryptString((string) time()) }}">
