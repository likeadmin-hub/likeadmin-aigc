export type SidebarKey = 'inspiration' | 'create' | 'avatar' | 'tools' | 'assets'
export type AvatarWorkspaceSidebar = Extract<SidebarKey, 'avatar' | 'tools' | 'assets'>

export const avatarPageSessionKey = 'ai-avatar-page-state'
export const sidebarRouteMap: Record<SidebarKey, string> = {
    inspiration: '/ai',
    create: '/ai/create',
    avatar: '/ai/avatar',
    tools: '/ai/tools',
    assets: '/ai/assets'
}

export const resolveAvatarWorkspaceSidebar = (value: unknown): AvatarWorkspaceSidebar | null => {
    const sidebar = Array.isArray(value) ? value[0] : value
    return sidebar === 'avatar' || sidebar === 'tools' || sidebar === 'assets' ? sidebar : null
}

export const buildSidebarRouteLocation = (sidebar: SidebarKey) => ({
    path: sidebarRouteMap[sidebar]
})
