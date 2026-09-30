/**
 * The live size of the catalog from the API. Nothing is shown until the number is real.
 */
function plural(n) {
  const tens = n % 100;
  const ones = n % 10;
  if (tens >= 11 && tens <= 14) return 'приложений';
  if (ones === 1) return 'приложение';
  if (ones >= 2 && ones <= 4) return 'приложения';
  return 'приложений';
}

export async function showCatalogCount(api) {
  try {
    const response = await api.get('/apps?per_page=1');
    const total = response.meta?.pagination?.total;
    if (!Number.isInteger(total) || total < 1) return;
    const words = `${total.toLocaleString('ru-RU')} ${plural(total)}`;
    const inline = document.querySelector('[data-catalog-count-inline]');
    if (inline) inline.innerHTML = `Сейчас в каталоге <b>${words}</b>.`;
  } catch {
    // The count is a detail; the page reads fine without it.
  }
}
