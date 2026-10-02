import { boot } from '../app.js';
import { setupPackages } from '../packages.js';
import { applyTelegramLinks, loadOffer } from '../store-offer.js';

const { api } = boot();
const packages = setupPackages();

// Prices and links as the bot has them now; the HTML defaults stay if this fails.
loadOffer(api).then((offer) => {
  packages?.applyOffer(offer);
  applyTelegramLinks(offer);
});
