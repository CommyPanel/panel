export type ContainerNetwork = { name: string; ip: string };
export type ContainerPort = {
    container: number;
    host: number;
    host_ip: string;
    protocol: string;
};
export type ContainerMount = {
    source: string;
    destination: string;
    read_only: boolean;
};

export type NetworkMember = {
    name: string;
    ip: string;
    // Added by the app's container endpoint.
    app?: { slug: string; name: string } | null;
    this_app?: boolean;
};

export type DockerNetwork = {
    name: string;
    driver: string;
    subnet: string;
    gateway: string;
    internal: boolean;
    containers: NetworkMember[];
};
