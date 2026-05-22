/** 弹窗打开时锁定页面滚动（与 PcLoginModal 行为一致） */
export function useModalBodyScrollLock() {
    let lockedScrollTop = 0
    let scrollLocked = false
    const previousHtmlStyle = { overflow: '' as string }
    const previousBodyStyle = {
        overflow: '',
        position: '',
        top: '',
        left: '',
        right: '',
        width: '',
        paddingRight: ''
    }

    const lock = () => {
        if (!import.meta.client || scrollLocked) return

        lockedScrollTop = window.scrollY || window.pageYOffset || 0
        const scrollbarWidth =
            window.innerWidth - document.documentElement.clientWidth

        previousHtmlStyle.overflow = document.documentElement.style.overflow
        previousBodyStyle.overflow = document.body.style.overflow
        previousBodyStyle.position = document.body.style.position
        previousBodyStyle.top = document.body.style.top
        previousBodyStyle.left = document.body.style.left
        previousBodyStyle.right = document.body.style.right
        previousBodyStyle.width = document.body.style.width
        previousBodyStyle.paddingRight = document.body.style.paddingRight

        document.documentElement.style.overflow = 'hidden'
        document.body.style.overflow = 'hidden'
        document.body.style.position = 'fixed'
        document.body.style.top = `-${lockedScrollTop}px`
        document.body.style.left = '0'
        document.body.style.right = '0'
        document.body.style.width = '100%'
        if (scrollbarWidth > 0) {
            document.body.style.paddingRight = `${scrollbarWidth}px`
        }

        scrollLocked = true
    }

    const unlock = () => {
        if (!import.meta.client || !scrollLocked) return

        document.documentElement.style.overflow = previousHtmlStyle.overflow
        document.body.style.overflow = previousBodyStyle.overflow
        document.body.style.position = previousBodyStyle.position
        document.body.style.top = previousBodyStyle.top
        document.body.style.left = previousBodyStyle.left
        document.body.style.right = previousBodyStyle.right
        document.body.style.width = previousBodyStyle.width
        document.body.style.paddingRight = previousBodyStyle.paddingRight

        window.scrollTo(0, lockedScrollTop)
        scrollLocked = false
    }

    return { lock, unlock }
}
