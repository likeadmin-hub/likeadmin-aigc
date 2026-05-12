export default () => ({
    title: '图片热区',
    name: 'image-hotspot',
    content: {
        enabled: 1,
        image: '',
        height: 180,
        fit: 'cover',
        areas: [
            {
                name: '热区',
                left: 0,
                top: 0,
                width: 50,
                height: 50,
                link: {}
            }
        ]
    },
    styles: {}
})
