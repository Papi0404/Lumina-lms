<?php
// This file is part of Lumina LMS - Academic & Student Lifecycle Management
//
// Lumina Academic Management is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Manajemen Akademik Lumina';
$string['academic_management'] = 'Manajemen Akademik';

// Navigasi Menu
$string['menu_overview'] = 'Ringkasan Akademik';
$string['menu_years'] = 'Tahun Ajaran';
$string['menu_classes'] = 'Manajemen Kelas (Rombel)';
$string['menu_groups'] = 'Kelompok Belajar Fleksibel';
$string['menu_assignments'] = 'Penugasan Mata Pelajaran';
$string['menu_promotion'] = 'Kenaikan Kelas (Promosi)';
$string['menu_graduation'] = 'Manajemen Kelulusan';
$string['menu_alumni'] = 'Buku Induk & Vault Alumni';

// Capabilities
$string['lumina_academic:manageyears'] = 'Mengelola tahun ajaran dan semester';
$string['lumina_academic:manageclasses'] = 'Mengelola kelas formal (rombel) dan wali kelas';
$string['lumina_academic:assigncourses'] = 'Menghubungkan mata pelajaran ke kelas atau kelompok';
$string['lumina_academic:promotestudents'] = 'Menjalankan wizard kenaikan kelas';
$string['lumina_academic:graduatestudents'] = 'Memvalidasi dan memproses kelulusan siswa';
$string['lumina_academic:managealumni'] = 'Mengelola akun alumni dan hak akses arsip';
$string['lumina_academic:viewalltranscripts'] = 'Melihat seluruh riwayat transkrip nilai akademik & buku induk';
$string['lumina_academic:correctgrades'] = 'Mengajukan dan mengesahkan koreksi nilai transkrip';
$string['lumina_academic:viewhomeroomclass'] = 'Melihat daftar siswa pada kelas perwalian';
$string['lumina_academic:viewhomeroomreports'] = 'Melihat dan mengekspor rekap nilai perwalian';
$string['lumina_academic:manageflexiblegroups'] = 'Membuat dan mengelola kelompok belajar fleksibel';
$string['lumina_academic:viewmyacademicrecord'] = 'Melihat riwayat akademik dan jadwal mata pelajaran sendiri';
$string['lumina_academic:viewalumnitranscript'] = 'Melihat dan mengunduh transkrip ijazah digital terverifikasi';

// Istilah Umum
$string['academic_year'] = 'Tahun Ajaran';
$string['semester'] = 'Semester';
$string['semester_odd'] = 'Ganjil (Semester 1)';
$string['semester_even'] = 'Genap (Semester 2)';
$string['status'] = 'Status';
$string['status_planning'] = 'Perencanaan';
$string['status_active'] = 'Aktif';
$string['status_closed'] = 'Ditutup';
$string['status_archived'] = 'Diarsipkan';
$string['is_current'] = 'Periode Berjalan';
$string['start_date'] = 'Tanggal Mulai';
$string['end_date'] = 'Tanggal Selesai';
$string['code'] = 'Kode';
$string['name'] = 'Nama';
$string['actions'] = 'Tindakan';
$string['details'] = 'Rincian';
$string['total_classes'] = 'Total Kelas';
$string['total_students'] = 'Total Siswa';
$string['total_courses'] = 'Total Mata Pelajaran';
$string['total_alumni'] = 'Total Alumni';

// Kelas Akademik
$string['class_name'] = 'Nama Kelas';
$string['class_code'] = 'Kode Kelas';
$string['grade_level'] = 'Tingkat Kelas';
$string['major'] = 'Jurusan / Peminatan';
$string['homeroom_teacher'] = 'Wali Kelas';
$string['mapped_cohort'] = 'Mapped Cohort Moodle';
$string['create_class'] = 'Tambah Kelas Akademik';
$string['edit_class'] = 'Ubah Kelas';
$string['add_students'] = 'Tambah Siswa';
$string['remove_student'] = 'Keluarkan Siswa';
$string['class_members'] = 'Anggota Kelas';
$string['no_classes'] = 'Belum ada kelas akademik yang terdaftar.';
$string['class_created'] = 'Kelas akademik berhasil ditambahkan.';
$string['class_updated'] = 'Kelas akademik berhasil diperbarui.';

// Kelompok Fleksibel
$string['flexible_groups'] = 'Kelompok Fleksibel';
$string['group_type'] = 'Jenis Kelompok';
$string['group_type_remedial'] = 'Remedial';
$string['group_type_competition'] = 'Tim Olimpiade / Lomba';
$string['group_type_project'] = 'Kelompok Proyek';
$string['group_type_extracurricular'] = 'Ekstrakurikuler';
$string['mentor'] = 'Guru Pembimbing';
$string['create_group'] = 'Buat Kelompok Fleksibel';

// Penugasan Mata Pelajaran
$string['assign_courses'] = 'Hubungkan Mapel ke Kelas';
$string['select_class'] = 'Pilih Kelas Akademik';
$string['select_courses'] = 'Pilih Mata Pelajaran (Course)';
$string['assigned_courses'] = 'Mata Pelajaran Terhubung';
$string['teacher_in_charge'] = 'Guru Pengampu';
$string['assignment_success'] = 'Mata pelajaran berhasil dihubungkan ke kelas cohort.';
$string['provenance_cohort'] = 'Sinkronisasi Cohort Kelas';
$string['provenance_flex'] = 'Sinkronisasi Kelompok Fleksibel';
$string['provenance_manual'] = 'Pendaftaran Manual';

// Mesin Kenaikan Kelas
$string['promotion_wizard'] = 'Wizard Kenaikan Kelas Siswa';
$string['bulk_promotion'] = 'Kenaikan Kelas Massal (1 Rombel)';
$string['individual_promotion'] = 'Kenaikan Kelas Individual';
$string['source_year'] = 'Tahun Ajaran Asal';
$string['source_class'] = 'Kelas Asal';
$string['target_year'] = 'Tahun Ajaran Tujuan';
$string['target_class'] = 'Kelas Tujuan';
$string['action_promote'] = 'Naik ke Tingkat Berikutnya';
$string['action_retain'] = 'Tinggal di Tingkat Ini';
$string['action_transfer'] = 'Mutasi ke Kelas / Jurusan Lain';
$string['action_graduate'] = 'Tandai untuk Kelulusan';
$string['preview_promotion'] = 'Pratinjau Kenaikan Kelas';
$string['execute_promotion'] = 'Eksekusi Batch Kenaikan Kelas';
$string['promotion_success'] = 'Proses kenaikan kelas berhasil dieksekusi.';
$string['rollback_batch'] = 'Batalkan & Kembalikan (Rollback)';
$string['batch_rolled_back'] = 'Batch promosi berhasil dibatalkan secara aman.';

// Kelulusan & Vault Alumni
$string['graduation_management'] = 'Manajemen Kelulusan';
$string['graduation_year'] = 'Tahun Kelulusan';
$string['eligibility_check'] = 'Verifikasi Kelayakan Kelulusan';
$string['eligible'] = 'Layak Lulus';
$string['ineligible'] = 'Belum Layak';
$string['process_graduation'] = 'Finalisasi Kelulusan';
$string['alumni_vault'] = 'Buku Induk & Vault Alumni';
$string['search_alumni'] = 'Cari Data Alumni berdasarkan NIS / NISN / Nama';
$string['nis'] = 'Nomor Induk Siswa (NIS)';
$string['nisn'] = 'NISN';
$string['cumulative_gpa'] = 'Nilai Rata-rata Kumulatif';
$string['final_predicate'] = 'Predikat Kelulusan';
$string['integrity_hash'] = 'Hash Integritas SHA-256';
$string['download_transcript'] = 'Unduh Transkrip Resmi (PDF)';
$string['correct_grade'] = 'Ajukan Koreksi Nilai';
$string['grade_corrected'] = 'Nilai berhasil dikoreksi dan versi transkrip baru telah diterbitkan.';

// Validasi & Error
$string['error_year_exists'] = 'Tahun ajaran dengan kode ini sudah terdaftar.';
$string['error_class_exists'] = 'Kelas dengan kode ini sudah terdaftar.';
$string['error_student_already_active'] = 'Siswa sudah memiliki keanggotaan kelas aktif pada tahun ajaran ini.';
$string['error_invalid_batch'] = 'Batch promosi tidak valid atau tidak ditemukan.';
$string['error_no_permission'] = 'Anda tidak memiliki wewenang untuk tindakan akademik ini.';

