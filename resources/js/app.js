// Alpine.js: 公開HP のヘッダー dropdown、language switcher 等で使用。
// 管理画面 (Filament) は Livewire バンドルの Alpine が別途動くので競合しない。
import Alpine from 'alpinejs';
window.Alpine = Alpine;
Alpine.start();

// 公開HP スクロール連動アニメーション
// [data-reveal] 属性を持つ要素が viewport に入ったら .revealed を付与し
// CSS transition で fade-in + slide-in する。
//
// 使い方:
//   <div data-reveal>...</div>                         // fade + up
//   <div data-reveal="left">...</div>                  // slide from left
//   <div data-reveal="right">...</div>                 // slide from right
//   <div data-reveal="scale">...</div>                 // scale up
//   <div data-reveal data-reveal-delay="200">...</div> // 200ms 遅延
//
// 1 度発火したら observer から外して以降処理をスキップ。
const initReveal = () => {
    const targets = document.querySelectorAll('[data-reveal]:not(.revealed)');
    if (targets.length === 0) {
        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('revealed');
                    observer.unobserve(entry.target);
                }
            });
        },
        { threshold: 0.15, rootMargin: '0px 0px -50px 0px' },
    );

    targets.forEach((el) => observer.observe(el));
};

document.addEventListener('DOMContentLoaded', initReveal);
// Livewire ナビゲーション等で DOM が入れ替わった場合も再スキャン
document.addEventListener('livewire:navigated', initReveal);
