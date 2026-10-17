<?php
/* TEMPLATE KONFIGURASI DATABASE — salin menjadi config/db.php di folder yang sama, lalu isi nilainya.
 *
 *   cp config/db.example.php config/db.php      (Windows: copy config\db.example.php config\db.php)
 *
 * Tidak perlu mengubah setting Apache / PHP-FPM: saved_data_store.php otomatis membaca config/db.php.
 * - Baris penjaga di bawah membuat file ini menjawab HTTP 404 tanpa isi bila dibuka langsung lewat URL.
 * - config/.htaccess juga menolak akses langsung bila server mengizinkan .htaccess.
 * - config/db.php berisi password: JANGAN commit ke SVN/Git (svn propset svn:ignore db.php config).
 * - Paket deployment hanya berisi db.example.php, sehingga mengekstrak paket baru tidak menimpa config/db.php Anda.
 * Kosongkan 'name' atau hapus config/db.php untuk kembali ke store lama; atau set 'report_store' => 'legacy'. */
if (!defined('PP_DB_CONFIG_LOADING')) { http_response_code(404); exit; }
return [
    'host'         => '127.0.0.1',
    'port'         => 3306,
    'name'         => '<db_name>',
    'user'         => '<app_user>',
    'password'     => '<strong_password>',
    // 'socket'    => '/var/run/mysqld/mysqld.sock',   // opsional, menggantikan host/port
    // 'report_store' => 'legacy',                     // opsional: paksa store lama (rollback) tanpa menghapus config
];
