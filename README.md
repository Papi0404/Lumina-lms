# Lumina LMS — Modern Enterprise Learning Management System

<p align="center">
  <img src="https://img.shields.io/badge/Release-v5.3%20Enterprise-4F46E5?style=for-the-badge&logo=rocket" alt="Release">
  <img src="https://img.shields.io/badge/Security-CBT%20Live%20Proctoring-10B981?style=for-the-badge&logo=shield" alt="Security">
  <img src="https://img.shields.io/badge/Lead%20Developer-Januar%20Zidane%20Tinendeng-0A31FA?style=for-the-badge" alt="Lead Developer">
  <img src="https://img.shields.io/badge/License-Proprietary%20Private-EF4444?style=for-the-badge" alt="License">
</p>

---

## 📌 Tentang Proyek
**Lumina LMS** adalah platform sistem manajemen pembelajaran (*Learning Management System*) enterprise generasi terbaru yang dibangun di atas fondasi Moodle 5.3+. Platform ini dirancang dengan estetika antarmuka SaaS modern, performa tinggi (*Time to First Byte* < 180ms), serta dipersenjatai sistem pengawasan ujian daring terproteksi (**CBT Live Proctoring**) dan tutor cerdas berbasis AI.

---

## 👨‍💻 Kontributor & Pengembang Utama
* **Lead Architect & Developer:** **Januar Zidane Tinendeng** ([@Papi0404](https://github.com/Papi0404))
* **Email:** `papijanuar147@gmail.com` / `januarzidane6@gmail.com`
* **Peran:** Perancangan Arsitektur Sistem, Rekayasa CBT Proctoring, Pembuatan Tema SaaS Lumina, Desain Antarmuka Multi-Role, serta Optimasi Integritas Basis Data.

---

## 🚀 Fitur Unggulan Sistem

### 1. 🛡️ CBT Live Proctoring & Security Engine (`local_lumina_core`)
* **Forced Fullscreen Gate:** Mengunci otomatis browser siswa ke mode layar penuh sebelum butir soal terbuka.
* **Hardware & OS Trap:** Mencegat shortcut `Alt+Tab`, hilangnya fokus jendela (*blur*), gesture touchpad swipe 3-4 jari (macOS & Windows), klik kanan, dan pintasan inspeksi element `F12`.
* **3-Strike Progressive Penalty:** Peringatan otomatis bertingkat (1/3 dan 2/3) hingga penangguhan permanen (*auto-lockout*) pada pelanggaran ke-3.
* **Live Audit Stream:** Dasbor pengawasan langsung bagi Admin/Pengawas untuk memantau linimasa kecurangan real-time dengan aksi *Unlock* dan *ACC Nilai Sah*.
* **Simulasi Uji Coba Pengajar:** Pengecekan kapabilitas dengan fitur *Self-Reset* mandiri bagi guru yang mencoba soal ujian.

### 2. 🎨 Modern SaaS UI/UX Overhaul (`theme_lumina`)
* **Fast Grader PDF:** Antarmuka penilaian tugas modern bebas tabrakan teks, kanvas slate berbayangan kertas, dan buffer scroll formulir nilai.
* **Sticky Submissions Table:** Kolom checkbox dan identitas siswa (nama & avatar) dikunci permanen di sisi kiri saat tabel digeser horizontal.
* **Instant Live User Search:** Fitur pencarian pengguna langsung tanpa reload halaman di panel administrasi.
* **Precompiled CSS Engine:** Membaca file CSS terkompilasi langsung untuk mengeliminasi beban runtime SCSS, menghasilkan TTFB < 180 ms.

### 3. 🤖 AI Academic Tutor (`local_lumina_ai`)
* Widget asisten pintar mengambang (*draggable floating action button*) yang dapat digeser bebas di layar.
* **AI Suppression Engine:** Fitur AI dimatikan otomatis selama sesi ujian CBT berlangsung guna mencegah kecurangan.

### 4. 👨‍🏫 Teacher Productivity Hub
* **Material & Module Builder Hub (`create_material.php`):** Penerbitan tugas, kuis, dan materi interaktif secara instan tanpa masuk ke pengaturan Moodle yang rumit.
* **Presensi Mandiri & Rekap CSV:** Input kehadiran kelas tatap muka dengan fitur ekspor lembar kerja `.csv`.
* **Konektor Kelas Virtual:** Tautan otomatis terintegrasi ke Zoom dan Google Meet.

---

## 📦 Paket Distribusi Plugin
Seluruh kustomisasi dikemas ke dalam 3 arsip instalasi modular (*zero core modification*):
1. `theme_lumina.zip` — Tema antarmuka SaaS modern Lumina.
2. `local_lumina_core.zip` — Modul proctoring CBT, presensi, perpustakaan, dan logika bisnis.
3. `local_lumina_ai.zip` — Modul tutor AI mengambang.

---

## 🔒 Hak Cipta & Lisensi
Hak Cipta © 2026 **Januar Zidane Tinendeng (Papi0404)**.  
Seluruh hak cipta dilindungi undang-undang. Proyek ini bersifat *Proprietary & Private*. Dilarang menyalin, mendistribusikan, atau menjual kembali tanpa izin tertulis dari pengembang utama.
