// The SQL surface the store needs. expo-sqlite backs it on the device and
// node:sqlite in tests, so the store runs the same in both.

export type SqlValue = string | number | null;

export interface SqlDatabase {
    exec(sql: string): void;
    run(sql: string, params?: SqlValue[]): { changes: number };
    all<T>(sql: string, params?: SqlValue[]): T[];
    get<T>(sql: string, params?: SqlValue[]): T | null;
}

export type Row = Record<string, SqlValue>;
