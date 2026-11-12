'use strict';
const path = require('path');
const fs = require('fs');
const dotenv = require('dotenv');

const root = path.resolve(__dirname, '..');
dotenv.config({ path: path.join(root, '.env') });

const bool = (v, d = false) => v == null ? d : /^(1|true|yes|on)$/i.test(String(v));
const int = (v, d) => {
  const n = parseInt(v, 10);
  return Number.isFinite(n) ? n : d;
};

const appUrl = process.env.APP_URL || 'http://127.0.0.1:4173';
const origin = (() => { try { return new URL(appUrl).origin; } catch { return 'http://127.0.0.1:4173'; } })();
const isProd = process.env.NODE_ENV === 'production';

const config = {
  root,
  isProd,
  port: int(process.env.PORT, 4173),
  appUrl,
  origin,
  secureCookies: appUrl.startsWith('https://'),
  sessionSecret: process.env.SESSION_SECRET || 'dev-only-change-me',
  sessionCookie: 'av_session',
  oauthCookie: 'av_oauth',
  sessionDays: 30,
  maxStateBytes: int(process.env.MAX_STATE_BYTES, 12 * 1024 * 1024),
  allowDemoLogin: bool(process.env.ALLOW_DEMO_LOGIN, !isProd),
  demoStateFile: path.join(root, 'db', 'demo-state.json'),
  db: {
    host: process.env.DB_HOST || '127.0.0.1',
    port: int(process.env.DB_PORT, 3306),
    user: process.env.DB_USER || 'alpha_vault',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || 'alpha_vault'
  },
  discord: {
    clientId: process.env.DISCORD_CLIENT_ID || '',
    clientSecret: process.env.DISCORD_CLIENT_SECRET || '',
    redirectUri: process.env.DISCORD_REDIRECT_URI || appUrl + '/auth/discord/callback',
    guildId: process.env.DISCORD_GUILD_ID || '',
    requiredRoleId: process.env.DISCORD_REQUIRED_ROLE_ID || '',
    botToken: process.env.DISCORD_BOT_TOKEN || '',
    defaultPlan: process.env.DISCORD_DEFAULT_PLAN === 'solo' ? 'solo' : 'full',
    trialDays: int(process.env.DISCORD_TRIAL_DAYS, 0)
  }
};

config.discordConfigured = !!(config.discord.clientId && config.discord.clientSecret && config.discord.redirectUri);
config.roleCheckConfigured = !!(config.discord.guildId && config.discord.requiredRoleId && config.discord.botToken);

if (isProd && (config.sessionSecret === 'dev-only-change-me' || !process.env.SESSION_SECRET)) {
  console.error('FATAL: ustaw SESSION_SECRET w .env przed uruchomieniem produkcyjnym.');
  process.exit(1);
}

module.exports = config;
