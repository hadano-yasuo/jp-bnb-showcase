<?php

return [
    /*
     * 身分証画像の保存先ディスク。config/filesystems.php で定義されているキーを指定する。
     * 本番は 'r2'（Cloudflare R2）、ローカル開発は 'local' にできる。
     */
    'disk' => env('GUEST_ID_IMAGE_DISK', 'r2'),
];
