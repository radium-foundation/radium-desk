import { HANDOFF_TTL_MS } from './constants.js';

/**
 * In-memory, one-time serial handoff. Never persisted.
 */
export class HandoffStore {
    /** @param {{ ttlMs?: number }} [options] */
    constructor(options = {}) {
        this.ttlMs = options.ttlMs ?? HANDOFF_TTL_MS;
        /** @type {Map<string, { oem: string, serial: string, createdAt: number, tabId: number | null, consumed: boolean }>} */
        this.byId = new Map();
        /** @type {Map<number, string>} */
        this.idByTabId = new Map();
    }

    /** @returns {void} */
    purgeExpired() {
        const now = Date.now();
        for (const [id, entry] of this.byId) {
            if (now - entry.createdAt > this.ttlMs) {
                this._delete(id);
            }
        }
    }

    /**
     * @param {{ oem: string, serial: string }} payload
     * @returns {{ handoffId: string }}
     */
    create(payload) {
        this.purgeExpired();
        const handoffId = crypto.randomUUID();
        this.byId.set(handoffId, {
            oem: payload.oem,
            serial: payload.serial,
            createdAt: Date.now(),
            tabId: null,
            consumed: false,
        });
        return { handoffId };
    }

    /** @param {string} handoffId @param {number} tabId */
    bindTab(handoffId, tabId) {
        const entry = this.byId.get(handoffId);
        if (!entry || entry.consumed) {
            return false;
        }
        entry.tabId = tabId;
        this.idByTabId.set(tabId, handoffId);
        return true;
    }

    /**
     * @param {{ handoffId?: string, tabId: number, oem: string }} query
     * @returns {{ serial: string } | null}
     */
    /**
     * When the OEM tab claims before bindTab runs, adopt the newest pending handoff for this OEM.
     *
     * @param {number} tabId
     * @param {string} oem
     * @returns {string | null}
     */
    adoptPendingHandoffForTab(tabId, oem) {
        let candidateId = null;
        let newestCreatedAt = 0;

        for (const [id, entry] of this.byId) {
            if (entry.consumed || entry.oem !== oem || entry.tabId !== null) {
                continue;
            }
            if (entry.createdAt >= newestCreatedAt) {
                newestCreatedAt = entry.createdAt;
                candidateId = id;
            }
        }

        if (!candidateId) {
            return null;
        }

        const entry = this.byId.get(candidateId);
        if (!entry) {
            return null;
        }

        entry.tabId = tabId;
        this.idByTabId.set(tabId, candidateId);
        return candidateId;
    }

    consume(query) {
        this.purgeExpired();
        let handoffId = query.handoffId ?? this.idByTabId.get(query.tabId);
        if (!handoffId) {
            handoffId = this.adoptPendingHandoffForTab(query.tabId, query.oem);
        }
        if (!handoffId) {
            return null;
        }
        const entry = this.byId.get(handoffId);
        if (!entry || entry.consumed || entry.oem !== query.oem) {
            return null;
        }
        if (entry.tabId !== null && entry.tabId !== query.tabId) {
            return null;
        }
        entry.consumed = true;
        const serial = entry.serial;
        this._delete(handoffId);
        return { serial };
    }

    /** @param {string} id */
    _delete(id) {
        const entry = this.byId.get(id);
        if (entry?.tabId != null) {
            this.idByTabId.delete(entry.tabId);
        }
        this.byId.delete(id);
    }

    /** @param {string} handoffId */
    discard(handoffId) {
        this._delete(handoffId);
    }
}
