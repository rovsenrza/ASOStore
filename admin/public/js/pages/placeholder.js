import { boot } from '../app.js';

// Pages whose admin API arrives in a later phase still get the shared shell.
await boot();
