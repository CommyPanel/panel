export type AlertKindValue = string;

export type AlertTargetRow = {
    id: number;
    name: string;
    kind: AlertKindValue;
    kind_label: string;
    host: string;
    chat_id: string | null;
    events: string[];
    enabled: boolean;
    last_ok_at: string | null;
    last_error: string | null;
};

export type AlertEventOption = {
    key: string;
    label: string;
    group: string;
    default_on: boolean;
};

export type AlertKindOption = {
    value: AlertKindValue;
    label: string;
    address_label: string;
    hint: string;
    needs_chat_id: boolean;
};
