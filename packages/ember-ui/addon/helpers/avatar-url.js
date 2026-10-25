import { helper } from '@ember/component/helper';

export default helper(function avatarUrl([url, defaultUrl = '/images/entregas/padrao/static/no-avatar.png']) {
    if (typeof url === 'string') {
        return url;
    }

    return defaultUrl;
});
