<?php
require 'inc.php'; need_login(); $u = me();
$tab = $_GET['tab'] ?? 'browse';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  check_token();
  if (isset($_POST['rm'])) { $_POST['a'] = 'remove'; $_POST['id'] = $_POST['rm']; }
  $a = $_POST['a'] ?? '';
  $id = (int)($_POST['id'] ?? 0);

  if ($a === 'add') {
    $k = $pdo->prepare('SELECT stock FROM catalog WHERE id=?');
    $k->execute([$id]); $stock = $k->fetchColumn();
    if ($stock === false || $stock <= 0) {
      flash('Sorry, that book is out of stock.', 'err');
      go('shop.php?tab=browse');
    }
    $pdo->prepare('INSERT IGNORE INTO cart(user_id,catalog_id,qty) VALUES(?,?,0)')->execute([$u['id'], $id]);
    $pdo->prepare('UPDATE cart SET qty=LEAST(qty+1,?) WHERE user_id=? AND catalog_id=?')->execute([$stock, $u['id'], $id]);
    flash('Added to your cart.');
    go('shop.php?tab=cart');
  }

  if ($a === 'update') {
    foreach (($_POST['qty'] ?? []) as $cid => $n) {
      $k = $pdo->prepare('SELECT stock FROM catalog WHERE id=?');
      $k->execute([(int)$cid]); $stock = (int)$k->fetchColumn(); $n = (int)$n;
      if ($n <= 0) $pdo->prepare('DELETE FROM cart WHERE user_id=? AND catalog_id=?')->execute([$u['id'], (int)$cid]);
      else $pdo->prepare('UPDATE cart SET qty=? WHERE user_id=? AND catalog_id=?')->execute([max(1, min($n, $stock)), $u['id'], (int)$cid]);
    }
    flash('Cart updated.');
    go('shop.php?tab=cart');
  }

  if ($a === 'remove') {
    $pdo->prepare('DELETE FROM cart WHERE user_id=? AND catalog_id=?')->execute([$u['id'], $id]);
    flash('Item removed.');
    go('shop.php?tab=cart');
  }

  if ($a === 'checkout') {
    $selected = array_map('intval', array_keys($_POST['selected'] ?? []));
    if (!$selected) {
      flash('Please select at least one book to checkout.', 'err');
      go('shop.php?tab=cart');
    }

    $addr = trim($_POST['address'] ?? '');
    $ph = trim($_POST['phone'] ?? '');
    if (mb_strlen($addr) < 8 || strlen($ph) < 7) {
      flash('Please enter your delivery address and phone number.', 'err');
      go('shop.php?tab=cart');
    }

    $in = implode(',', array_fill(0, count($selected), '?'));
    $s = $pdo->prepare('SELECT c.catalog_id,c.qty,k.title,k.price,k.stock FROM cart c JOIN catalog k ON k.id=c.catalog_id WHERE c.user_id=? AND c.catalog_id IN (' . $in . ')');
    $args = [$u['id'], ...$selected];
    $s->execute($args); $items = $s->fetchAll();
    if (!$items) { go('shop.php?tab=cart'); }

    $checkedItems = [];
    foreach ($items as $i) {
      $qty = max(1, min((int)($_POST['qty'][$i['catalog_id']] ?? $i['qty']), (int)$i['stock']));
      $i['qty'] = $qty;
      $checkedItems[] = $i;
    }

    foreach ($checkedItems as $i) if ($i['qty'] > $i['stock']) {
      flash('“' . $i['title'] . '” only has ' . $i['stock'] . ' left. Please adjust your cart.', 'err');
      go('shop.php?tab=cart');
    }

    $total = array_sum(array_map(fn($i) => $i['price'] * $i['qty'], $checkedItems));
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO orders(user_id,total,address,phone) VALUES(?,?,?,?)')->execute([$u['id'], $total, $addr, $ph]); $oid = (int)$pdo->lastInsertId();
    foreach ($checkedItems as $i) {
      $pdo->prepare('INSERT INTO order_items(order_id,catalog_id,title,price,qty) VALUES(?,?,?,?,?)')->execute([$oid, $i['catalog_id'], $i['title'], $i['price'], $i['qty']]);
      $pdo->prepare('UPDATE catalog SET stock=stock-? WHERE id=?')->execute([$i['qty'], $i['catalog_id']]);
      $pdo->prepare('DELETE FROM cart WHERE user_id=? AND catalog_id=?')->execute([$u['id'], $i['catalog_id']]);
    }
    $pdo->commit();
    flash('Order #' . $oid . ' placed!');
    go('shop.php?tab=orders');
  }
}

$q = trim($_GET['q'] ?? ''); $g = $_GET['genre'] ?? ''; $sort = $_GET['sort'] ?? 'title';
$w = []; $p = [];
if ($q !== '') { $w[] = '(title LIKE ? OR author LIKE ? OR isbn LIKE ? OR genre LIKE ?)'; array_push($p, ...array_fill(0, 4, "%$q%")); }
if ($g !== '') { $w[] = 'genre=?'; $p[] = $g; }
$o = ['price' => 'price ASC', 'price_desc' => 'price DESC', 'new' => 'id DESC'][$sort] ?? 'title';
$s = $pdo->prepare('SELECT * FROM catalog' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY (stock=0), $o"); $s->execute($p); $rows = $s->fetchAll();
$genres = array_column($pdo->query("SELECT DISTINCT genre v FROM catalog WHERE genre<>'' ORDER BY genre")->fetchAll(), 'v');

$cartItems = $pdo->prepare('SELECT c.qty,k.* FROM cart c JOIN catalog k ON k.id=c.catalog_id WHERE c.user_id=? ORDER BY k.title');
$cartItems->execute([$u['id']]); $items = $cartItems->fetchAll();
$cartTotal = array_sum(array_map(fn($i) => $i['price'] * $i['qty'], $items));

$orders = $pdo->prepare('SELECT o.*,(SELECT COALESCE(SUM(qty),0) FROM order_items WHERE order_id=o.id) n FROM orders o WHERE user_id=? ORDER BY id DESC');
$orders->execute([$u['id']]); $userOrders = $orders->fetchAll();

top('Shop', 'shop'); ?>
<div class="head"><div><h1>Book Shop</h1><p class="mut">Browse books, manage your cart, and track your orders in one place.</p></div></div>
<nav class="sub">
  <a href="shop.php?tab=browse" class="<?= $tab === 'browse' ? 'on' : '' ?>">Browse</a>
  <a href="shop.php?tab=cart" class="<?= $tab === 'cart' ? 'on' : '' ?>">Cart</a>
  <a href="shop.php?tab=orders" class="<?= $tab === 'orders' ? 'on' : '' ?>">Orders</a>
</nav>

<?php if ($tab === 'browse'): ?>
<form class="glass filters" method="get"><div class="frow">
  <input type="hidden" name="tab" value="browse">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search title, author, ISBN or genre" aria-label="Search">
  <select name="genre" onchange="this.form.submit()" aria-label="Genre"><option value="">All genres</option><?php foreach ($genres as $x): ?><option <?= $g === $x ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select>
  <select name="sort" onchange="this.form.submit()" aria-label="Sort"><?php foreach (['title' => 'Title', 'price' => 'Price: low to high', 'price_desc' => 'Price: high to low', 'new' => 'Newest'] as $k => $l): ?><option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <button class="btn sm">Search</button></div></form>
<?php if (!$rows): ?><div class="glass empty"><p>No books match your search.</p><a class="btn ghost" href="shop.php?tab=browse">Clear filters</a></div><?php else: ?>
<section class="grid shop-grid">
<?php foreach ($rows as $i => $b): ?>
  <article class="book glass shop-book" style="--i:<?= min($i, 12) ?>">
    <div class="cvw"><?= cover($b['cover'], $b['title']) ?></div>
    <div class="bi">
      <h3><?= e($b['title']) ?></h3><p class="by"><?= e($b['author']) ?></p>
      <p class="pills"><?= $b['genre'] ? '<span class="pill">' . e($b['genre']) . '</span>' : '' ?>
        <?= $b['stock'] <= 0 ? '<span class="pill st-cancelled">Out of stock</span>' : ($b['stock'] <= 5 ? '<span class="pill st-pending">Only ' . (int)$b['stock'] . ' left</span>' : '<span class="pill st-completed">In stock</span>') ?></p>
      <p class="mut sm clamp"><?= e($b['description']) ?></p>
      <p class="price"><?= money($b['price']) ?></p>
      <div class="acts">
        <form method="post" action="shop.php?tab=cart"><?= tf() ?><input type="hidden" name="a" value="add"><input type="hidden" name="id" value="<?= $b['id'] ?>"><button class="btn glow sm" <?= $b['stock'] <= 0 ? 'disabled' : '' ?>>Add to cart</button></form>
      </div>
    </div>
  </article>
<?php endforeach; ?></section><?php endif; ?>
<?php elseif ($tab === 'cart'): ?>
<?php if (!$items): ?><div class="glass empty"><p>Your cart is empty.</p><a class="btn glow" href="shop.php?tab=browse">Browse books</a></div>
<?php else: ?>
<form class="glass formcard" method="post" action="shop.php?tab=cart"><?= tf() ?>
  <?php foreach ($items as $i): ?>
  <div class="line" data-price="<?= (float)$i['price'] ?>" data-id="<?= $i['id'] ?>">
    <label class="check" aria-label="Select <?= e($i['title']) ?> for checkout"><input type="checkbox" name="selected[<?= $i['id'] ?>]" value="1" checked></label>
    <div class="cvw xs"><?= cover($i['cover'], $i['title']) ?></div>
    <div class="grow"><b><?= e($i['title']) ?></b><p class="mut sm"><?= e($i['author']) ?> · <?= money($i['price']) ?></p></div>
    <div class="qtybox">
      <button type="button" class="qty-btn" data-id="<?= $i['id'] ?>" data-change="-1" aria-label="Decrease quantity for <?= e($i['title']) ?>">−</button>
      <input class="qty" type="number" name="qty[<?= $i['id'] ?>]" value="<?= (int)$i['qty'] ?>" min="1" max="<?= (int)$i['stock'] ?>" aria-label="Quantity for <?= e($i['title']) ?>">
      <button type="button" class="qty-btn" data-id="<?= $i['id'] ?>" data-change="1" aria-label="Increase quantity for <?= e($i['title']) ?>">+</button>
    </div>
    <b class="lt"><?= money($i['price'] * $i['qty']) ?></b>
    <button class="btn danger sm" type="submit" name="a" value="remove" formnovalidate aria-label="Remove <?= e($i['title']) ?>">✕</button>
    <input type="hidden" name="id" value="<?= $i['id'] ?>">
  </div>
  <?php endforeach; ?>

  <div class="checkout-box">
    <h2>Checkout</h2>
    <p class="total"><span>Total</span><b><?= money($cartTotal) ?></b></p>
    <label>Delivery address<textarea name="address" rows="3" required placeholder="House no., street, city"></textarea></label>
    <label>Phone<input name="phone" inputmode="tel" required></label>
    <button class="btn glow wide" type="submit" name="a" value="checkout">Place order</button>
    <p class="mut sm">Only selected books will be checked out.</p>
  </div>
</form>
<?php endif; ?>
<?php else: ?>
<?php if (!$userOrders): ?><div class="glass empty"><p>You haven't ordered anything yet.</p><a class="btn glow" href="shop.php?tab=browse">Browse books</a></div>
<?php else: ?><section class="glass tw"><table>
  <thead><tr><th>Order</th><th>Date</th><th>Items</th><th>Total</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($userOrders as $o): ?><tr><td data-l="Order">#<?= $o['id'] ?></td><td data-l="Date"><?= e(substr($o['created_at'], 0, 10)) ?></td><td data-l="Items"><?= (int)$o['n'] ?></td>
    <td data-l="Total"><?= money($o['total']) ?></td><td data-l="Status"><?= pill($o['status']) ?></td><td><a class="btn ghost sm" href="order.php?id=<?= $o['id'] ?>">Track</a></td></tr><?php endforeach; ?>
</tbody></table></section><?php endif; ?>
<?php endif; bottom();
