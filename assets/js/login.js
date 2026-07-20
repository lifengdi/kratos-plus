/* Kratos+ 自定义登录页交互
 * - 主题切换（亮/暗）持久化到 localStorage
 */
(function () {
  var root = document.documentElement;
  var KEY = 'kratos-theme';
  var saved = null;
  try { saved = localStorage.getItem(KEY); } catch (e) {}
  if (saved === 'dark' || saved === 'light') {
    root.setAttribute('data-theme', saved);
  } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
    root.setAttribute('data-theme', 'dark');
  }

  document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('kratosLoginThemeToggle');
    if (btn) {
      btn.addEventListener('click', function () {
        var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem(KEY, next); } catch (e) {}
      });
    }

    // 密码显隐（capture 阶段抢在 WP user-profile.js 之前，避免被其覆盖）
    var pwBtn = document.querySelector('.wp-hide-pw');
    if (pwBtn) {
      pwBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        var input = document.getElementById('user_pass');
        if (!input) return;
        var showing = input.getAttribute('type') === 'text';
        input.setAttribute('type', showing ? 'password' : 'text');
        var icon = pwBtn.querySelector('.dashicons');
        if (icon) {
          if (showing) {
            icon.classList.add('dashicons-visibility');
            icon.classList.remove('dashicons-hidden');
          } else {
            icon.classList.add('dashicons-hidden');
            icon.classList.remove('dashicons-visibility');
          }
        }
        pwBtn.setAttribute('aria-label', showing ? '显示密码' : '隐藏密码');
      }, true);
    }

    // 把 #nav 中的「忘记密码？」链接搬到 forgetmenot 行，与「记住我」同行
    var nav = document.getElementById('nav');
    var forget = document.querySelector('p.forgetmenot');
    if (nav && forget) {
      var lost = nav.querySelector('.wp-login-lost-password');
      if (lost) {
        var wrap = document.createElement('span');
        wrap.className = 'kratos-login-lost';
        wrap.appendChild(lost);
        forget.appendChild(wrap);
      }
      // 移除 #nav 里剩余的注册链接（Tabs 已提供）
      if (nav.children.length === 0 || !nav.textContent.trim()) {
        nav.style.display = 'none';
      } else {
        nav.style.display = 'none';
      }
    }
  });
})();
