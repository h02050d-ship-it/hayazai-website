// =====================================================
// 林材木店 カート管理
// localStorage ベース
// =====================================================

const CART_KEY = 'hayazai_cart';

// ---------- カートデータ操作 ----------

function getCart() {
  try {
    return JSON.parse(localStorage.getItem(CART_KEY) || '[]');
  } catch {
    return [];
  }
}

function saveCart(cart) {
  localStorage.setItem(CART_KEY, JSON.stringify(cart));
  updateCartUI();
}

function addToCart(productId, qty = 1) {
  const product = PRODUCTS.find(p => p.id === productId);
  if (!product) return false;

  const cart = getCart();
  const existing = cart.find(item => item.id === productId);

  if (existing) {
    existing.qty += qty;
  } else {
    cart.push({
      id:    product.id,
      name:  product.name,
      price: product.price,
      img:   product.img,
      qty:   qty,
    });
  }

  saveCart(cart);
  showToast(`カートに追加しました`);
  return true;
}

function removeFromCart(productId) {
  const cart = getCart().filter(item => item.id !== productId);
  saveCart(cart);
}

function updateQty(productId, qty) {
  const cart = getCart();
  const item = cart.find(i => i.id === productId);
  if (item) {
    item.qty = Math.max(1, qty);
    saveCart(cart);
  }
}

function clearCart() {
  saveCart([]);
}

function getCartTotal() {
  return getCart().reduce((sum, item) => sum + item.price * item.qty, 0);
}

function getCartCount() {
  return getCart().reduce((sum, item) => sum + item.qty, 0);
}

// ---------- UI 更新 ----------

function updateCartUI() {
  const count = getCartCount();
  document.querySelectorAll('.cart-count').forEach(el => {
    el.textContent = count;
    el.classList.toggle('hidden', count === 0);
  });
}

// ---------- トースト通知 ----------

function showToast(msg, duration = 2800) {
  let container = document.querySelector('.toast-container');
  if (!container) {
    container = document.createElement('div');
    container.className = 'toast-container';
    document.body.appendChild(container);
  }

  const toast = document.createElement('div');
  toast.className = 'toast';
  toast.textContent = msg;
  container.appendChild(toast);

  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transition = 'opacity 0.3s';
    setTimeout(() => toast.remove(), 300);
  }, duration);
}

// ---------- 商品カードの「カートに入れる」ボタン ----------

function handleAddToCart(btn) {
  const productId = btn.dataset.id;
  const qtyInput = btn.closest('.product-card-actions')?.querySelector('.qty-num');
  const qty = qtyInput ? parseInt(qtyInput.value) || 1 : 1;
  addToCart(productId, qty);
}

// ---------- カートページ描画 ----------

function renderCart() {
  const cart = getCart();
  const container = document.getElementById('cart-items');
  const summary = document.getElementById('cart-summary');
  if (!container) return;

  if (cart.length === 0) {
    container.innerHTML = `
      <div class="cart-empty-msg">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
            d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
        </svg>
        <p>カートに商品がありません</p>
        <a href="products.html" class="btn btn-primary" style="margin-top:20px;">商品一覧へ</a>
      </div>`;
    if (summary) summary.style.display = 'none';
    return;
  }

  if (summary) summary.style.display = '';

  container.innerHTML = `
    <table class="data-table" style="min-width:600px;">
      <thead>
        <tr>
          <th style="width:60px;"></th>
          <th>商品</th>
          <th style="width:100px;">単価</th>
          <th style="width:130px;">数量</th>
          <th style="width:110px;">小計</th>
          <th style="width:48px;"></th>
        </tr>
      </thead>
      <tbody>
        ${cart.map(item => `
          <tr data-id="${item.id}">
            <td>
              <img src="${item.img}" alt="${item.name}"
                style="width:52px;height:52px;object-fit:cover;border-radius:4px;background:#f5ede0;">
            </td>
            <td>
              <div style="font-weight:700;font-size:0.85rem;color:var(--wood-dark);line-height:1.5;">${item.name}</div>
            </td>
            <td style="font-weight:700;">¥${item.price.toLocaleString()}</td>
            <td>
              <div class="qty-input">
                <button class="qty-btn" onclick="changeQty('${item.id}',-1)">−</button>
                <input class="qty-num" type="number" value="${item.qty}" min="1"
                  onchange="updateQty('${item.id}', parseInt(this.value)); renderCart();">
                <button class="qty-btn" onclick="changeQty('${item.id}',1)">＋</button>
              </div>
            </td>
            <td style="font-weight:700;">¥${(item.price * item.qty).toLocaleString()}</td>
            <td>
              <button onclick="removeFromCart('${item.id}'); renderCart();"
                style="background:none;border:none;cursor:pointer;color:#aaa;font-size:1.2rem;"
                title="削除">✕</button>
            </td>
          </tr>
        `).join('')}
      </tbody>
    </table>`;

  const total = getCartTotal();
  const totalEl = document.getElementById('cart-total');
  if (totalEl) totalEl.textContent = `¥${total.toLocaleString()}`;
}

function changeQty(productId, delta) {
  const cart = getCart();
  const item = cart.find(i => i.id === productId);
  if (item) {
    item.qty = Math.max(1, item.qty + delta);
    saveCart(cart);
    renderCart();
  }
}

// ---------- 注文ページ: カート内容表示 ----------

function renderOrderSummary() {
  const cart = getCart();
  const el = document.getElementById('order-items');
  if (!el) return;

  if (cart.length === 0) {
    window.location.href = 'cart.html';
    return;
  }

  const total = getCartTotal();

  el.innerHTML = cart.map(item => `
    <tr>
      <td>${item.name}</td>
      <td style="text-align:right;">¥${item.price.toLocaleString()}</td>
      <td style="text-align:center;">${item.qty}</td>
      <td style="text-align:right;">¥${(item.price * item.qty).toLocaleString()}</td>
    </tr>
  `).join('');

  const totalEl = document.getElementById('order-total');
  if (totalEl) totalEl.textContent = `¥${total.toLocaleString()}`;

  // hidden input にカートJSON埋め込み
  const hiddenEl = document.getElementById('cart-json');
  if (hiddenEl) hiddenEl.value = JSON.stringify(cart);
}

// ---------- 初期化 ----------

document.addEventListener('DOMContentLoaded', () => {
  updateCartUI();

  // ハンバーガーメニュー
  const hamburger = document.getElementById('hamburger');
  const mobileNav = document.getElementById('mobile-nav');
  const mobileClose = document.getElementById('mobile-close');

  if (hamburger && mobileNav) {
    hamburger.addEventListener('click', () => mobileNav.classList.add('open'));
  }
  if (mobileClose && mobileNav) {
    mobileClose.addEventListener('click', () => mobileNav.classList.remove('open'));
  }
  if (mobileNav) {
    mobileNav.addEventListener('click', e => {
      if (e.target === mobileNav) mobileNav.classList.remove('open');
    });
  }

  // FAQ アコーディオン
  document.querySelectorAll('.faq-q').forEach(q => {
    q.addEventListener('click', () => {
      q.closest('.faq-item').classList.toggle('open');
    });
  });
});
